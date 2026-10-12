<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkMatrix;

test('latency percentiles use the nearest rank over ok results', function (): void {
    $run = BenchmarkRun::factory()->completed()->create();
    $case = BenchmarkCase::factory()->create();

    foreach ([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000] as $latency) {
        BenchmarkResult::factory()->forCase($run, BenchmarkCase::factory()->create(), 0)->scored(0.5, true)->create(['latency_ms' => $latency]);
    }

    BenchmarkResult::factory()->forCase($run, $case, 0)->failed('boom')->create(['latency_ms' => 99999]);

    $candidate = BenchmarkMatrix::for($run)[0]['candidates'][0];

    expect($candidate['latency_p50'])->toBe(500)
        ->and($candidate['latency_p95'])->toBe(1000)
        ->and($candidate['results'])->toBe(11)
        ->and($candidate['failed'])->toBe(1);
});

test('a candidate without scored results has null figures and never wins', function (): void {
    $run = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'a', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'b', 'reasoning' => 'low'],
    ])->create();
    $case = BenchmarkCase::factory()->create();
    BenchmarkResult::factory()->forCase($run, $case, 0)->failed('boom')->create();
    BenchmarkResult::factory()->forCase($run, $case, 1)->scored(0.4, false)->create(['latency_ms' => 10, 'cost_usd' => 0.5, 'judge_cost_usd' => 0.25]);

    $matrix = BenchmarkMatrix::for($run)[0];

    expect($matrix['candidates'][0]['avg_score'])->toBeNull()
        ->and($matrix['candidates'][0]['pass_rate'])->toBeNull()
        ->and($matrix['candidates'][0]['latency_p50'])->toBeNull()
        ->and($matrix['candidates'][0]['cost_per_case'])->toBeNull()
        ->and($matrix['candidates'][0]['reasoning_label'])->toBe('Provider default')
        ->and($matrix['candidates'][1]['cost_per_case'])->toBe(0.75)
        ->and($matrix['best'])->toBe(['avg_score' => 1, 'pass_rate' => 1, 'cost_per_case' => 1, 'latency_p50' => 1]);
});

test('each task present in the run gets its own matrix', function (): void {
    $run = BenchmarkRun::factory()->completed()->create(['judge' => null]);
    BenchmarkResult::factory()->forCase($run, BenchmarkCase::factory()->forTask(AiTask::Title)->create(), 0)->create(['status' => BenchmarkResultStatus::Pending]);
    BenchmarkResult::factory()->forCase($run, BenchmarkCase::factory()->create(), 0)->create(['status' => BenchmarkResultStatus::Pending]);

    $matrix = BenchmarkMatrix::for($run);

    expect(array_column($matrix, 'task'))->toBe(['title', 'decision'])
        ->and($matrix[0]['candidates'][0]['self_judged'])->toBeFalse();
});

test('a candidate with only pending results has no cost per case', function (): void {
    $run = BenchmarkRun::factory()->running()->withCandidates([
        ['provider' => 'openai', 'model' => 'a', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'b', 'reasoning' => null],
    ])->create();
    $case = BenchmarkCase::factory()->create();
    BenchmarkResult::factory()->forCase($run, $case, 0)->create(['status' => BenchmarkResultStatus::Pending]);
    BenchmarkResult::factory()->forCase($run, $case, 1)->scored(1.0, true)->create(['cost_usd' => 0.2]);

    $matrix = BenchmarkMatrix::for($run)[0];

    expect($matrix['candidates'][0]['cost_per_case'])->toBeNull()
        ->and($matrix['candidates'][1]['cost_per_case'])->toBe(0.2)
        ->and($matrix['best']['cost_per_case'])->toBe(1);
});
