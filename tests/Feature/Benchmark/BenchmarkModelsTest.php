<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkRunStatus;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;

test('a case round-trips its json columns and task enum', function (): void {
    $case = BenchmarkCase::factory()
        ->forTask(AiTask::Decision)
        ->expectingActions([['type' => 'search']])
        ->withFixtures([['tool' => 'x', 'result' => ['a' => 1]]])
        ->create(['tags' => ['smoke', 'tv']]);

    $fresh = $case->fresh();

    expect($fresh->task)->toBe(AiTask::Decision)
        ->and($fresh->tags)->toBe(['smoke', 'tv'])
        ->and($fresh->fixtures)->toBe([['tool' => 'x', 'result' => ['a' => 1]]])
        ->and($fresh->input)->toBe(['prompt' => 'Download stuck at 99% for two days'])
        ->and($fresh->scoring)->toBe(['type' => 'actions', 'expected' => [['type' => 'search']]])
        ->and($fresh->source_truncated)->toBeFalse();
});

test('deleting a case keeps its results with the copied name', function (): void {
    $run = BenchmarkRun::factory()->create();
    $case = BenchmarkCase::factory()->create(['name' => 'Stuck download']);
    $result = BenchmarkResult::factory()->for($run, 'run')->for($case, 'benchmarkCase')->create(['case_name' => 'Stuck download']);

    $case->delete();

    $result->refresh();
    expect($result->benchmark_case_id)->toBeNull()
        ->and($result->case_name)->toBe('Stuck download');
});

test('deleting a run deletes its results and nulls usage record links', function (): void {
    $run = BenchmarkRun::factory()->create();
    $case = BenchmarkCase::factory()->create();
    $result = BenchmarkResult::factory()->forCase($run, $case, 0)->create();
    $usage = AiUsageRecord::factory()->create(['benchmark_run_id' => $run->id]);

    expect($usage->benchmarkRun->is($run))->toBeTrue();

    $run->delete();

    expect(BenchmarkResult::query()->whereKey($result->id)->exists())->toBeFalse()
        ->and($usage->fresh()->benchmark_run_id)->toBeNull();
});

test('a run reports whether it is terminal', function (BenchmarkRunStatus $status, bool $terminal): void {
    expect(BenchmarkRun::factory()->make(['status' => $status])->isTerminal())->toBe($terminal);
})->with([
    [BenchmarkRunStatus::Pending, false],
    [BenchmarkRunStatus::Running, false],
    [BenchmarkRunStatus::Completed, true],
    [BenchmarkRunStatus::StoppedCostCap, true],
    [BenchmarkRunStatus::Cancelled, true],
    [BenchmarkRunStatus::Failed, true],
]);
