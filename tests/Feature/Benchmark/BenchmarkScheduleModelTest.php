<?php

declare(strict_types=1);

use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;

test('cadence labels read as a sentence', function (): void {
    expect(BenchmarkSchedule::factory()->make(['time_of_day' => '04:30'])->cadenceLabel())->toBe('Daily at 04:30')
        ->and(BenchmarkSchedule::factory()->weekly(1)->make()->cadenceLabel())->toBe('Weekly on Monday at 03:00')
        ->and(BenchmarkSchedule::factory()->monthly(15)->make()->cadenceLabel())->toBe('Monthly on day 15 at 03:00');
});

test('a schedule keeps its runs and its latest run', function (): void {
    $benchmarkSchedule = BenchmarkSchedule::factory()->create();
    $older = BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->completed()->create();
    $newer = BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->running()->create();

    expect($benchmarkSchedule->runs()->pluck('id')->all())->toEqualCanonicalizing([$older->id, $newer->id])
        ->and($benchmarkSchedule->latestRun->id)->toBe($newer->id)
        ->and($newer->schedule->id)->toBe($benchmarkSchedule->id);
});

test('deleting a schedule keeps its runs unlinked', function (): void {
    $benchmarkSchedule = BenchmarkSchedule::factory()->create();
    $benchmarkRun = BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->completed()->create();

    $benchmarkSchedule->delete();

    expect($benchmarkRun->fresh()->benchmark_schedule_id)->toBeNull();
});

test('a run remembers its baseline and regressions', function (): void {
    $baseline = BenchmarkRun::factory()->completed()->create();
    $benchmarkRun = BenchmarkRun::factory()->completed()->create(['baseline_run_id' => $baseline->id, 'regressions' => []]);

    expect($benchmarkRun->fresh()->baseline->id)->toBe($baseline->id)
        ->and($benchmarkRun->fresh()->regressions)->toBe([]);
});

test('a stopped-cost-cap run is terminal', function (): void {
    expect(BenchmarkRun::factory()->stoppedCostCap()->create()->isTerminal())->toBeTrue();
});
