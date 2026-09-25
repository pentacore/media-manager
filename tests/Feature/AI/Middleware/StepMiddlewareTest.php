<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\SubtitleAdvisorAgent;
use App\Ai\Middleware\AnswerOnFinalStep;
use App\Ai\Middleware\EnforceBudgetEachStep;
use App\Models\AiModelPrice;
use App\Services\AiBudget\AiBudgetExceededException;
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

    expect(fn () => (new EnforceBudgetEachStep)->handle(stepMiddlewarePendingStep(3, false, new TextUsage(1_000_000, 0)), fn (): string => 'ok'))
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

test('every tool-using agent runs both step middleware', function (string $agentClass): void {
    $agent = new $agentClass;

    expect($agent)->toBeInstanceOf(HasMiddleware::class)
        ->and(array_map(static fn (object $middleware): string => $middleware::class, $agent->middleware()))
        ->toBe([AnswerOnFinalStep::class, EnforceBudgetEachStep::class]);
})->with([
    'media' => MediaAgent::class,
    'decision' => DecisionAgent::class,
    'subtitle advisor' => SubtitleAdvisorAgent::class,
    'price fetcher' => PriceFetcherAgent::class,
]);
