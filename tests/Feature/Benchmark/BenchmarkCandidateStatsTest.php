<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Services\Benchmark\BenchmarkCandidateStats;

test('candidate statistics count statuses, average scores and price each finished case', function (): void {
    $results = collect([
        BenchmarkResult::factory()->make(['status' => BenchmarkResultStatus::Ok, 'score' => 0.8, 'passed' => true, 'cost_usd' => '0.010000', 'judge_cost_usd' => '0.002000', 'latency_ms' => 400, 'tool_misses' => 1]),
        BenchmarkResult::factory()->make(['status' => BenchmarkResultStatus::Ok, 'score' => 0.4, 'passed' => false, 'cost_usd' => '0.010000', 'judge_cost_usd' => '0.002000', 'latency_ms' => 1200, 'tool_misses' => 0]),
        BenchmarkResult::factory()->make(['status' => BenchmarkResultStatus::Failed, 'cost_usd' => '0.004000', 'judge_cost_usd' => '0', 'latency_ms' => null, 'tool_misses' => 2]),
        BenchmarkResult::factory()->make(['status' => BenchmarkResultStatus::Skipped]),
    ]);

    expect(BenchmarkCandidateStats::of($results))->toBe([
        'results' => 4,
        'ok' => 2,
        'failed' => 1,
        'skipped' => 1,
        'scored' => 2,
        'avg_score' => 0.6,
        'pass_rate' => 0.5,
        'cost_per_case' => 0.014,
        'latency_p50' => 400,
        'latency_p95' => 1200,
        'tool_misses' => 3,
    ]);
});

test('candidate statistics of no finished result are empty', function (): void {
    expect(BenchmarkCandidateStats::of(collect()))->toMatchArray([
        'results' => 0,
        'scored' => 0,
        'avg_score' => null,
        'pass_rate' => null,
        'cost_per_case' => null,
        'latency_p50' => null,
    ]);
});

test('a case is judged by its scoring type or its task default', function (): void {
    expect(BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->make()->isJudged())->toBeTrue()
        ->and(BenchmarkCase::factory()->make()->isJudged())->toBeFalse()
        ->and(BenchmarkCase::factory()->forTask(AiTask::Title)->make(['scoring' => []])->isJudged())->toBeTrue();
});
