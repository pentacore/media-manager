<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Services\Benchmark\BenchmarkRegressionDetector;

const REGRESSION_CANDIDATE = ['provider' => 'openai', 'model' => 'gpt-tier', 'reasoning' => 'provider_default'];

/**
 * A finished run of the schedule with one result per case for candidate 0:
 * each outcome is `[score, passed]` or 'failed'.
 *
 * @param  list<BenchmarkCase>  $cases
 * @param  list<array{0: float, 1: bool}|string>  $outcomes
 * @param  array<string, mixed>  $attributes
 */
function scheduledRunWith(BenchmarkSchedule $benchmarkSchedule, array $cases, array $outcomes, array $attributes = [], array $candidate = REGRESSION_CANDIDATE): BenchmarkRun
{
    $benchmarkRun = BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->completed()->withCandidates([$candidate])->create($attributes);

    foreach ($cases as $i => $case) {
        $factory = BenchmarkResult::factory()->forCase($benchmarkRun, $case, 0);
        $outcome = $outcomes[$i];
        ($outcome === 'failed' ? $factory->failed('Provider error') : $factory->scored($outcome[0], $outcome[1]))->create();
    }

    return $benchmarkRun;
}

beforeEach(function (): void {
    $this->schedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['pass_rate_threshold' => 10, 'score_threshold' => 10]);
    $this->cases = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->count(4)->create()->all();
});

test('a first run has no baseline and no regressions', function (): void {
    $benchmarkRun = scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.9, true]));

    expect(resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun))->toBe(['baseline_run_id' => null, 'regressions' => []]);
});

test('a pass rate drop over the threshold is a regression', function (): void {
    $baseline = scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.9, true]));
    $benchmarkRun = scheduledRunWith($this->schedule, $this->cases, [[0.9, true], [0.9, true], [0.9, false], [0.9, false]]);

    $detected = resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun);

    expect($detected['baseline_run_id'])->toBe($baseline->id)
        ->and($detected['regressions'])->toHaveCount(1)
        ->and($detected['regressions'][0]['candidate'])->toBe(REGRESSION_CANDIDATE)
        ->and($detected['regressions'][0]['paired_cases'])->toBe(4)
        ->and($detected['regressions'][0]['metrics'])->toBe([['metric' => 'pass_rate', 'before' => 1.0, 'after' => 0.5]]);
});

test('a drop within the threshold is not a regression', function (): void {
    scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.90, true]));
    $benchmarkRun = scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.82, true]));

    expect(resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun)['regressions'])->toBe([]);
});

test('a score drop over the threshold is a regression', function (): void {
    scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.9, true]));
    $benchmarkRun = scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.7, true]));

    $metrics = resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun)['regressions'][0]['metrics'];

    expect($metrics)->toBe([['metric' => 'avg_score', 'before' => 0.9, 'after' => 0.7]]);
});

test('a model that now fails every case regresses on its error rate', function (): void {
    scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.9, true]));
    $benchmarkRun = scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, 'failed'));

    $metrics = resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun)['regressions'][0]['metrics'];

    expect($metrics)->toBe([['metric' => 'error_rate', 'before' => 0.0, 'after' => 1.0]]);
});

test('only cases present in both runs are compared', function (): void {
    [$a, $b, $c, $d] = $this->cases;
    scheduledRunWith($this->schedule, [$a, $b], [[0.9, true], [0.9, true]]);
    $benchmarkRun = scheduledRunWith($this->schedule, [$a, $b, $c, $d], [[0.9, true], [0.9, true], [0.1, false], [0.1, false]]);

    expect(resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun)['regressions'])->toBe([]);
});

test('a run judged by another model is not a baseline', function (): void {
    scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.9, true]), ['judge' => ['provider' => 'openai', 'model' => 'old-judge', 'reasoning' => 'provider_default']]);
    $benchmarkRun = scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.1, false]));

    expect(resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun))->toBe(['baseline_run_id' => null, 'regressions' => []]);
});

test('a candidate absent from earlier runs is skipped and another one still compares', function (): void {
    scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.9, true]), candidate: ['provider' => 'openai', 'model' => 'gpt-old', 'reasoning' => 'provider_default']);
    $benchmarkRun = scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.1, false]));

    expect(resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun)['regressions'])->toBe([]);
});

test('cancelled runs and other schedules never serve as a baseline', function (): void {
    scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.9, true]), ['status' => 'cancelled']);
    $other = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['judge' => $this->schedule->judge]);
    scheduledRunWith($other, $this->cases, array_fill(0, 4, [0.9, true]));
    $benchmarkRun = scheduledRunWith($this->schedule, $this->cases, array_fill(0, 4, [0.1, false]));

    expect(resolve(BenchmarkRegressionDetector::class)->detect($benchmarkRun)['baseline_run_id'])->toBeNull();
});
