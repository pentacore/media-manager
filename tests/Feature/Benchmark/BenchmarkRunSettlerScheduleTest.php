<?php

declare(strict_types=1);

use App\Jobs\CheckBenchmarkRegression;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Services\Benchmark\BenchmarkRunSettler;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('settling a scheduled run to completed queues the regression check', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->forSchedule(BenchmarkSchedule::factory()->create())->running()->create();
    BenchmarkResult::factory()->forCase($benchmarkRun, BenchmarkCase::factory()->create(), 0)->scored(0.9, true)->create();

    resolve(BenchmarkRunSettler::class)->settle($benchmarkRun->id);

    Queue::assertPushed(CheckBenchmarkRegression::class, fn (CheckBenchmarkRegression $job): bool => $job->runId === $benchmarkRun->id);
});

test('settling a scheduled run at its cost cap queues the regression check', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->forSchedule(BenchmarkSchedule::factory()->create())->running()->create(['cost_cap_usd' => 0.000001, 'cost_usd' => 1]);
    BenchmarkResult::factory()->forCase($benchmarkRun, BenchmarkCase::factory()->create(), 0)->create();

    resolve(BenchmarkRunSettler::class)->settle($benchmarkRun->id);

    expect($benchmarkRun->fresh()->status->value)->toBe('stopped_cost_cap');
    Queue::assertPushed(CheckBenchmarkRegression::class, 1);
});

test('an unscheduled run, an already ended run and an unfinished run queue nothing', function (): void {
    $unscheduled = BenchmarkRun::factory()->running()->create();
    BenchmarkResult::factory()->forCase($unscheduled, BenchmarkCase::factory()->create(), 0)->scored(0.9, true)->create();
    $cancelled = BenchmarkRun::factory()->forSchedule(BenchmarkSchedule::factory()->create())->cancelled()->create();
    $unfinished = BenchmarkRun::factory()->forSchedule(BenchmarkSchedule::factory()->create())->running()->create();
    BenchmarkResult::factory()->forCase($unfinished, BenchmarkCase::factory()->create(), 0)->create();

    foreach ([$unscheduled, $cancelled, $unfinished] as $benchmarkRun) {
        resolve(BenchmarkRunSettler::class)->settle($benchmarkRun->id);
    }

    Queue::assertNotPushed(CheckBenchmarkRegression::class);
});
