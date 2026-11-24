<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Jobs\RunBenchmarkCase;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkRunSettler;
use Illuminate\Queue\Attributes\UniqueFor;

beforeEach(function (): void {
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.retry_after', 330);
});

function stalledBenchmarkResult(BenchmarkRun $benchmarkRun, BenchmarkCase $benchmarkCase, bool $stale): BenchmarkResult
{
    $benchmarkResult = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->running()->create();

    if ($stale) {
        stalledBenchmarkAge($benchmarkResult, BenchmarkResult::sweepAfterSeconds() + 1);
    }

    return $benchmarkResult;
}

function stalledBenchmarkAge(BenchmarkResult $benchmarkResult, int $seconds): void
{
    BenchmarkResult::query()->whereKey($benchmarkResult->id)->toBase()->update([
        'updated_at' => now()->subSeconds($seconds),
    ]);
}

test('only the result past the stale window fails and a run with a fresh running result stays running', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->running()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $benchmarkResult = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, true);
    $fresh = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, false);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->fresh()->error)->toBe('Worker lost before finishing')
        ->and($fresh->fresh()->status)->toBe(BenchmarkResultStatus::Running)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Running);
});

test('a run whose only unfinished results were stale completes', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->running()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $benchmarkResult = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, true);
    $done = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create(['status' => BenchmarkResultStatus::Ok]);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($done->fresh()->status)->toBe(BenchmarkResultStatus::Ok)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed)
        ->and($benchmarkRun->fresh()->finished_at)->not->toBeNull();
});

test('a run that already ended fails its stale result and keeps its status', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->completed()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $benchmarkResult = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, true);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($benchmarkResult->fresh()->error)->toBe(BenchmarkRunSettler::STOPPED_ERROR)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
});

test('the sweep waits past the queue retry and one more job run before it fails a dead claim', function (): void {
    expect(BenchmarkResult::sweepAfterSeconds())->toBe(330 + RunBenchmarkCase::TIMEOUT_SECONDS)
        ->and(BenchmarkResult::sweepAfterSeconds())->toBeGreaterThan(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS);

    config()->set('queue.connections.redis.retry_after', 10);

    expect(BenchmarkResult::sweepAfterSeconds())->toBe(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS);
});

test('a dead claim the queue will still retry is left for the retry to take over', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->running()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $benchmarkResult = stalledBenchmarkResult($benchmarkRun, $benchmarkCase, false);
    stalledBenchmarkAge($benchmarkResult, 331);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($benchmarkResult->fresh()->status)->toBe(BenchmarkResultStatus::Running)
        ->and($benchmarkResult->fresh()->isRunStale())->toBeTrue()
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Running);
});

test('a pending result whose job was lost before it claimed anything fails after the unique window', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->running()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $lost = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    stalledBenchmarkAge($lost, BenchmarkResult::LOST_AFTER_SECONDS + 1);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($lost->fresh()->status)->toBe(BenchmarkResultStatus::Failed)
        ->and($lost->fresh()->error)->toBe(BenchmarkRunSettler::NEVER_STARTED_ERROR)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Completed);
});

test('a pending result still inside the unique window keeps waiting for its job', function (): void {
    $benchmarkRun = BenchmarkRun::factory()->running()->create();
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $waiting = BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->create();
    stalledBenchmarkAge($waiting, BenchmarkResult::LOST_AFTER_SECONDS - 60);

    $this->artisan('benchmark:settle-stalled')->assertSuccessful();

    expect($waiting->fresh()->status)->toBe(BenchmarkResultStatus::Pending)
        ->and($benchmarkRun->fresh()->status)->toBe(BenchmarkRunStatus::Running);
});

test('the lost window matches the job unique window', function (): void {
    $uniqueFor = new ReflectionClass(RunBenchmarkCase::class)->getAttributes(UniqueFor::class)[0]->getArguments()[0];

    expect(BenchmarkResult::LOST_AFTER_SECONDS)->toBe($uniqueFor);
});
