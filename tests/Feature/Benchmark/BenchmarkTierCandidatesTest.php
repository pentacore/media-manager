<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Services\Benchmark\BenchmarkTierCandidates;

beforeEach(function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
});

test('a task with configured tiers yields each tier once', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-a')->create();
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-b')->position(1)->create();

    $candidates = resolve(BenchmarkTierCandidates::class)->forTask(AiTask::Title);

    expect(array_column($candidates, 'model'))->toBe(['gpt-a', 'gpt-b'])
        ->and($candidates[0]['provider'])->toBe('openai')
        ->and($candidates[0]['reasoning'])->toBeString();
});

test('a task without tiers falls back to its resolved model', function (): void {
    $candidates = resolve(BenchmarkTierCandidates::class)->forTask(AiTask::Title);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['model'])->not->toBe('');
});

test('all() covers only benchmarkable tasks', function (): void {
    $all = resolve(BenchmarkTierCandidates::class)->all();

    expect(array_keys($all))->not->toContain(AiTask::BenchmarkJudge->value)
        ->and(array_keys($all))->toContain(AiTask::Decision->value)
        ->and(array_keys($all))->toContain(AiTask::DecisionVerifier->value)
        ->and(array_keys($all))->not->toContain(AiTask::Failover->value);
});
