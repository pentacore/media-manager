<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Agents\TitleAgent;
use App\Ai\Decision\DecisionRunContext;
use App\Ai\Decision\DecisionRunOutOfTime;
use App\Ai\Middleware\AnswerOnFinalStep;
use App\Ai\Middleware\ClientDisconnectedException;
use App\Ai\Middleware\EnforceBudgetEachStep;
use App\Ai\Middleware\ReplayBenchmarkTools;
use App\Ai\Middleware\StopWhenClientDisconnected;
use App\Ai\Middleware\StopWhenDecisionRunOutOfTime;
use App\Ai\Middleware\StopWhenPriceRefreshOutOfTime;
use App\Http\Streaming\ClientConnection;
use App\Models\AiModelPrice;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiUsage\Pricing\PriceRefreshOutOfTime;
use App\Services\AiUsage\Pricing\PriceRefreshTimeBox;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Middleware\Stop;
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

test('every agent runs the step middleware its runs need', function (string $agentClass, array $expected): void {
    $agent = new $agentClass;

    expect($agent)->toBeInstanceOf(HasMiddleware::class)
        ->and(array_map(static fn (object $middleware): string => $middleware::class, $agent->middleware()))
        ->toBe($expected);
})->with([
    'media' => [MediaAgent::class, [AnswerOnFinalStep::class, Stop::class, EnforceBudgetEachStep::class, ReplayBenchmarkTools::class]],
    'stuck download investigator' => [StuckDownloadInvestigatorAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class, StopWhenClientDisconnected::class, ReplayBenchmarkTools::class]],
    'media file inspector' => [MediaFileInspectorAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class, StopWhenClientDisconnected::class, ReplayBenchmarkTools::class]],
    'decision' => [DecisionAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class, StopWhenDecisionRunOutOfTime::class, ReplayBenchmarkTools::class]],
    'price refresh agent' => [PriceFetcherAgent::class, [AnswerOnFinalStep::class, EnforceBudgetEachStep::class, StopWhenPriceRefreshOutOfTime::class, ReplayBenchmarkTools::class]],
    'title' => [TitleAgent::class, [ReplayBenchmarkTools::class]],
    'benchmark judge' => [BenchmarkJudgeAgent::class, [ReplayBenchmarkTools::class]],
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

test('the price refresh agent stops before a later step that no longer fits the refresh time box', function (): void {
    $this->freezeTime();
    resolve(PriceRefreshTimeBox::class)->open(PriceRefreshTimeBox::AGENT_STEP_SECONDS - 1);

    expect(fn (): mixed => (new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(3, false), fn (): string => 'ran'))
        ->toThrow(PriceRefreshOutOfTime::class);
});

test('a provider failover restarting the run at step 0 is checked too, not exempted as the first step', function (): void {
    $this->freezeTime();
    resolve(PriceRefreshTimeBox::class)->open(PriceRefreshTimeBox::AGENT_STEP_SECONDS - 1);

    // laravel/ai's failover re-runs the whole agent loop on the fallback
    // provider starting at step 0 again, so step 0 must stop a run just like
    // any later step once the box no longer fits — it is not exempt as the
    // overall run's "first step".
    expect(fn (): mixed => (new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(0, false), fn (): string => 'ran'))
        ->toThrow(PriceRefreshOutOfTime::class);
});

test('the price refresh agent keeps going while a step still fits, and when no time box was opened', function (): void {
    $this->freezeTime();

    expect((new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(3, false), fn (): string => 'ran'))->toBe('ran');

    resolve(PriceRefreshTimeBox::class)->open(PriceRefreshTimeBox::AGENT_STEP_SECONDS);

    expect((new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(3, false), fn (): string => 'ran'))->toBe('ran');
});

test('the price refresh agent step timeout is cut to the time left in the box', function (?int $timeout, int $boxSeconds, int $expected): void {
    $this->freezeTime();
    resolve(PriceRefreshTimeBox::class)->open($boxSeconds);
    $seen = null;

    (new StopWhenPriceRefreshOutOfTime)->handle(
        new PendingStep(...[...get_object_vars(stepMiddlewarePendingStep(2, false)), 'timeout' => $timeout]),
        function (PendingStep $step) use (&$seen): string {
            $seen = $step;

            return 'ran';
        },
    );

    expect($seen->timeout)->toBe($expected);
})->with([
    'an explicit timeout longer than the time left' => [200, 120, 120],
    'an explicit timeout that fits' => [30, 120, 30],
    'no timeout takes the SDK default, which fits' => [null, 120, PriceRefreshTimeBox::MODEL_CALL_SECONDS],
    'no timeout with exactly one step left' => [null, PriceRefreshTimeBox::AGENT_STEP_SECONDS, PriceRefreshTimeBox::MODEL_CALL_SECONDS],
]);

test('the price refresh agent step timeout is left alone when no time box was opened', function (): void {
    $seen = null;

    (new StopWhenPriceRefreshOutOfTime)->handle(stepMiddlewarePendingStep(2, false), function (PendingStep $step) use (&$seen): string {
        $seen = $step;

        return 'ran';
    });

    expect($seen->timeout)->toBeNull();
});

function stepMiddlewareDecisionRun(?int $secondsLeft): void
{
    app()->instance(DecisionRunContext::class, new DecisionRunContext(null, 3, 'sonarr', deadline: $secondsLeft === null ? null : now()->addSeconds($secondsLeft)));
}

test('a decision run step is cut to the time left before the run deadline', function (?int $stepTimeout, int $secondsLeft, int $expected): void {
    $this->freezeTime();
    stepMiddlewareDecisionRun($secondsLeft);
    $step = new PendingStep(number: 2, isFinalStep: false, provider: 'openai', model: 'gpt-5-mini', instructions: 'Decide.', messages: [], tools: [], schema: null, options: null, timeout: $stepTimeout, invocationId: 'inv-mw');
    $seen = null;

    (new StopWhenDecisionRunOutOfTime)->handle($step, function (PendingStep $pendingStep) use (&$seen): string {
        $seen = $pendingStep;

        return 'ran';
    });

    expect($seen->timeout)->toBe($expected)
        ->and($seen->number)->toBe(2)
        ->and($seen->instructions)->toBe('Decide.')
        ->and($seen->invocationId)->toBe('inv-mw');
})->with([
    'a step timeout longer than the time left' => [60, 25, 25],
    'a step timeout shorter than the time left' => [20, 100, 20],
    'no step timeout' => [null, 100, 100],
]);

test('a decision run step that no longer fits before the deadline is refused, step 0 included', function (int $number): void {
    $this->freezeTime();
    stepMiddlewareDecisionRun(StopWhenDecisionRunOutOfTime::MIN_STEP_SECONDS - 1);

    expect(fn (): mixed => (new StopWhenDecisionRunOutOfTime)->handle(stepMiddlewarePendingStep($number, false), fn (): string => 'ran'))
        ->toThrow(DecisionRunOutOfTime::class);
})->with([0, 3]);

test('a step outside a bounded decision run passes through untouched', function (bool $bound): void {
    if ($bound) {
        stepMiddlewareDecisionRun(null);
    }

    $step = stepMiddlewarePendingStep(1, false);

    expect((new StopWhenDecisionRunOutOfTime)->handle($step, fn (PendingStep $pendingStep): PendingStep => $pendingStep))->toBe($step);
})->with(['no run context' => [false], 'a run without a deadline' => [true]]);
