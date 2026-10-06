<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Middleware\AnswerOnFinalStep;
use App\Ai\Middleware\ClientDisconnectedException;
use App\Ai\Middleware\EnforceBudgetEachStep;
use App\Ai\Middleware\StopWhenClientDisconnected;
use App\Ai\Middleware\StopWhenPriceRefreshOutOfTime;
use App\Http\Streaming\ClientConnection;
use App\Models\AiModelPrice;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiUsage\Pricing\PriceRefreshOutOfTime;
use App\Services\AiUsage\Pricing\PriceRefreshTimeBox;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\ToolChoice;

function stepMiddlewarePendingStep(int $number, bool $isFinalStep, TextUsage $usage = new TextUsage): PendingStep
{
    return new PendingStep(number: $number, isFinalStep: $isFinalStep, provider: 'openai', model: 'gpt-5-mini', instructions: null, messages: [], tools: [], schema: null, options: null, usage: $usage, invocationId: 'inv-mw');
}

test('the final step forbids tool calls', function (): void {
    $seen = null;
    (new AnswerOnFinalStep)->handle(stepMiddlewarePendingStep(5, true), function (PendingStep $step) use (&$seen): string {
        $seen = $step;

        return 'ok';
    });

    expect($seen->options->toolChoice->mode)->toBe(ToolChoice::none);
});

test('earlier steps pass through untouched', function (): void {
    $step = stepMiddlewarePendingStep(2, false);
    $seen = null;
    (new AnswerOnFinalStep)->handle($step, function (PendingStep $pendingStep) use (&$seen): string {
        $seen = $pendingStep;

        return 'ok';
    });

    expect($seen)->toBe($step);
});

test('a step that would cross the hard cap is refused', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 10, 'output_per_mtok' => 0]);
    resolve(AiSettings::class)->setHardBudgetUsd(5.0);

    expect(fn (): mixed => (new EnforceBudgetEachStep)->handle(stepMiddlewarePendingStep(3, false, new TextUsage(1_000_000, 0)), fn (): string => 'ok'))
        ->toThrow(AiBudgetExceededException::class);
});

test('a later step under the hard cap proceeds', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1, 'output_per_mtok' => 0]);
    resolve(AiSettings::class)->setHardBudgetUsd(5.0);

    expect((new EnforceBudgetEachStep)->handle(stepMiddlewarePendingStep(3, false, new TextUsage(1_000_000, 0)), fn (): string => 'ok'))->toBe('ok');
});

test('the first step is not re-checked', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(0.0);

    expect((new EnforceBudgetEachStep)->handle(stepMiddlewarePendingStep(0, false), fn (): string => 'ok'))->toBe('ok');
});

test('every tool-using agent runs the step middleware its runs need', function (string $agentClass, array $expected): void {
    $agent = new $agentClass;

    expect($agent)->toBeInstanceOf(HasMiddleware::class)
        ->and(array_map(static fn (object $middleware): string => $middleware::class, $agent->middleware()))
        ->toBe($expected);
})->with([
    'media' => [MediaAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class, StopWhenClientDisconnected::class]],
    'stuck download investigator' => [StuckDownloadInvestigatorAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class, StopWhenClientDisconnected::class]],
    'media file inspector' => [MediaFileInspectorAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class, StopWhenClientDisconnected::class]],
    'decision' => [DecisionAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class]],
    'price fetcher' => [PriceFetcherAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class, StopWhenPriceRefreshOutOfTime::class]],
]);

/**
 * A client connection whose browser has gone.
 */
function stepMiddlewareGoneClient(bool $watched): ClientConnection
{
    $clientConnection = new class extends ClientConnection
    {
        #[Override]
        protected function pollConnection(): void {}

        #[Override]
        protected function connectionAborted(): bool
        {
            return true;
        }
    };

    if ($watched) {
        $clientConnection->watch();
    }

    app()->instance(ClientConnection::class, $clientConnection);

    return $clientConnection;
}

test('the connection is polled before it is read, and only while watched', function (): void {
    $clientConnection = new class extends ClientConnection
    {
        public int $polls = 0;

        #[Override]
        protected function pollConnection(): void
        {
            $this->polls++;
        }

        #[Override]
        protected function connectionAborted(): bool
        {
            return $this->polls > 0;
        }
    };

    expect($clientConnection->disconnected())->toBeFalse()
        ->and($clientConnection->polls)->toBe(0);

    $clientConnection->watch();

    expect($clientConnection->disconnected())->toBeTrue()
        ->and($clientConnection->polls)->toBe(1);
});

test('a later step stops once the watched chat client disconnected', function (): void {
    stepMiddlewareGoneClient(watched: true);

    expect(fn (): mixed => (new StopWhenClientDisconnected)->handle(stepMiddlewarePendingStep(2, false), fn (): string => 'ok'))
        ->toThrow(ClientDisconnectedException::class);
});

test('the first step always runs', function (): void {
    stepMiddlewareGoneClient(watched: true);

    expect((new StopWhenClientDisconnected)->handle(stepMiddlewarePendingStep(0, false), fn (): string => 'ok'))->toBe('ok');
});

test('an unwatched connection never stops a run', function (): void {
    stepMiddlewareGoneClient(watched: false);

    expect((new StopWhenClientDisconnected)->handle(stepMiddlewarePendingStep(2, false), fn (): string => 'ok'))->toBe('ok');
});

test('the price verifier stops before a later step that no longer fits the refresh time box', function (): void {
    $this->freezeTime();
    resolve(PriceRefreshTimeBox::class)->open(PriceRefreshTimeBox::AGENT_STEP_SECONDS - 1);

    expect(fn (): mixed => (new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(3, false), fn (): string => 'ran'))
        ->toThrow(PriceRefreshOutOfTime::class)
        ->and((new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(0, false), fn (): string => 'ran'))->toBe('ran');
});

test('the price verifier keeps going while a step still fits, and when no time box was opened', function (): void {
    $this->freezeTime();

    expect((new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(3, false), fn (): string => 'ran'))->toBe('ran');

    resolve(PriceRefreshTimeBox::class)->open(PriceRefreshTimeBox::AGENT_STEP_SECONDS);

    expect((new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(3, false), fn (): string => 'ran'))->toBe('ran');
});
