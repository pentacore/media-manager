<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\BenchmarkCase;
use App\Services\Benchmark\BenchmarkEstimator;

beforeEach(function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-candidate', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'claude-candidate', 'input_per_mtok' => 3, 'output_per_mtok' => 15]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
});

/**
 * @return array{provider: string, model: string, reasoning: string}
 */
function estimatorSelection(string $provider, string $model): array
{
    return ['provider' => $provider, 'model' => $model, 'reasoning' => 'provider_default'];
}

test('priced candidates sum their source token counts across cases', function (): void {
    $captured = BenchmarkCase::factory()->create(['source_prompt_tokens' => 10_000, 'source_completion_tokens' => 2_000]);
    $handWritten = BenchmarkCase::factory()->create();

    $estimate = resolve(BenchmarkEstimator::class)->estimate(
        collect([$captured, $handWritten]),
        [estimatorSelection('openai', 'gpt-candidate'), estimatorSelection('anthropic', 'claude-candidate')],
        null,
    );

    expect($estimate['rows'])->toBe([
        ['case_id' => $captured->id, 'candidate_index' => 0, 'usd' => 0.036, 'priced' => true],
        ['case_id' => $captured->id, 'candidate_index' => 1, 'usd' => 0.06, 'priced' => true],
        ['case_id' => $handWritten->id, 'candidate_index' => 0, 'usd' => 0.008, 'priced' => true],
        ['case_id' => $handWritten->id, 'candidate_index' => 1, 'usd' => 0.0135, 'priced' => true],
    ])
        ->and($estimate['judge_usd'])->toBe(0.0)
        ->and($estimate['total_usd'])->toBe(0.1175)
        ->and($estimate['unpriced'])->toBe([]);
});

test('judged cases add the judge allowance for every candidate', function (): void {
    $judged = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $checked = BenchmarkCase::factory()->create();

    $estimate = resolve(BenchmarkEstimator::class)->estimate(
        collect([$judged, $checked]),
        [estimatorSelection('openai', 'gpt-candidate'), estimatorSelection('anthropic', 'claude-candidate')],
        estimatorSelection('openai', 'gpt-judge'),
    );

    // Judge prompt 1 500 + 2 × 500 output allowance, 300 completion: 2 500 × $1 + 300 × $4 per Mtok.
    expect($estimate['judge_usd'])->toBe(0.0074)
        ->and($estimate['total_usd'])->toBe(0.0504);
});

test('the judge allowance doubles a captured case output allowance', function (): void {
    $judged = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['source_prompt_tokens' => 4_000, 'source_completion_tokens' => 1_000]);

    $estimate = resolve(BenchmarkEstimator::class)->estimate(
        collect([$judged]),
        [estimatorSelection('openai', 'gpt-candidate')],
        estimatorSelection('openai', 'gpt-judge'),
    );

    expect($estimate['judge_usd'])->toBe(0.0047);
});

test('an unpriced candidate and judge contribute nothing and are listed once', function (): void {
    $judged = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $other = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $estimate = resolve(BenchmarkEstimator::class)->estimate(
        collect([$judged, $other]),
        [estimatorSelection('openai', 'gpt-candidate'), estimatorSelection('mistral', 'mistral-unpriced')],
        estimatorSelection('anthropic', 'claude-unpriced-judge'),
    );

    expect(array_column($estimate['rows'], 'priced'))->toBe([true, false, true, false])
        ->and(array_column($estimate['rows'], 'usd'))->toBe([0.008, 0.0, 0.008, 0.0])
        ->and($estimate['judge_usd'])->toBe(0.0)
        ->and($estimate['total_usd'])->toBe(0.016)
        ->and($estimate['unpriced'])->toBe(['mistral/mistral-unpriced', 'anthropic/claude-unpriced-judge']);
});

test('an unknown provider or incomplete selection never throws', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $estimate = resolve(BenchmarkEstimator::class)->estimate(
        collect([$benchmarkCase]),
        [estimatorSelection('no-such-provider', 'whatever'), ['provider' => '', 'model' => '']],
        ['model' => 'judge-without-provider'],
    );

    expect($estimate['total_usd'])->toBe(0.0)
        ->and($estimate['rows'])->toHaveCount(2)
        ->and($estimate['unpriced'])->toContain('no-such-provider/whatever');
});

test('a dated model variant is priced at its base model rate', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->create();

    $estimate = resolve(BenchmarkEstimator::class)->estimate(
        collect([$benchmarkCase]),
        [estimatorSelection('openai', 'gpt-candidate-2026-09-01')],
        null,
    );

    expect($estimate['rows'][0])->toMatchArray(['usd' => 0.008, 'priced' => true]);
});
