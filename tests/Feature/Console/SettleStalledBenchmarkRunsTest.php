<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;

function stalledBenchmarkResult(BenchmarkRun $benchmarkRun, BenchmarkCase $benchmarkCase, bool $stale): BenchmarkResult
{
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->running()->create();

    if ($stale) {
        BenchmarkResult::query()->whereKey($benchmarkResult->id)->toBase()->update([
            'updated_at' => now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS + 1),
        ]);
    }

    return $benchmarkResult;
}

test('only the result past the stale window fails and a run with a fresh running result stays running', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->running()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $stale = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, true);
    $fresh = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, false);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($stale->fresh()->error)->toBe('Worker lost before finishing')
        ->and($fresh->fresh()->status)->toBe(BenchmarkResultStatus::Running)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Running);
});

test('a run whose only unfinished results were stale completes', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->running()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $stale = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, true);
    $done = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create(['status' => BenchmarkResultStatus::Ok]);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($done->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and($benchmarkRun->fresh()->finished_at)->not->toBeNull();
});

test('a run that already ended is left alone', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->completed()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $stale = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, true);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(BenchmarkResultStatus::Running);
});
