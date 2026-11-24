<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\UserRole;
use App\Jobs\CheckBenchmarkRegression;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Models\User;
use App\Notifications\ScheduledBenchmarkAlert;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->schedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create();
    $this->case = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $this->adminCount = User::query()->where('role', UserRole::Admin)->count();
});

function regressionRun(BenchmarkSchedule $benchmarkSchedule, BenchmarkCase $benchmarkCase, bool $passed): BenchmarkRun
{
    $benchmarkRun = BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->completed()
        ->withCandidates([['provider' => 'openai', 'model' => 'gpt-tier', 'reasoning' => 'provider_default']])->create();
    BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->scored($passed ? 0.9 : 0.1, $passed)->create();

    return $benchmarkRun;
}

test('the job stores the comparison and alerts admins on a regression', function (): void {
    $baseline = regressionRun($this->schedule, $this->case, true);
    $benchmarkRun = regressionRun($this->schedule, $this->case, false);

    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id));

    $benchmarkRun->refresh();
    expect($benchmarkRun->baseline_run_id)->toBe($baseline->id)
        ->and($benchmarkRun->regressions)->toHaveCount(1)
        ->and($this->adminCount)->toBeGreaterThan(0);
    Notification::assertSentTimes(ScheduledBenchmarkAlert::class, $this->adminCount);
});

test('the job stores an empty list and stays quiet without a regression', function (): void {
    regressionRun($this->schedule, $this->case, true);
    $benchmarkRun = regressionRun($this->schedule, $this->case, true);

    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id));

    expect($benchmarkRun->fresh()->regressions)->toBe([]);
    Notification::assertNothingSent();
});

test('a checked run is never checked twice', function (): void {
    regressionRun($this->schedule, $this->case, true);
    $benchmarkRun = regressionRun($this->schedule, $this->case, false);

    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id));
    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id));

    Notification::assertSentTimes(ScheduledBenchmarkAlert::class, $this->adminCount);
});

test('a recheck replaces the stored comparison and alerts only on new regressions', function (): void {
    $baseline = regressionRun($this->schedule, $this->case, true);
    $benchmarkRun = regressionRun($this->schedule, $this->case, false);
    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id));
    $stored = $benchmarkRun->fresh()->regressions;

    // Re-judging left the same regression: the comparison is refreshed without a second alert.
    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id, recheck: true));

    expect($benchmarkRun->fresh()->regressions)->toBe($stored);
    Notification::assertSentTimes(ScheduledBenchmarkAlert::class, $this->adminCount);

    // Re-judging cleared it: the stored comparison follows.
    $benchmarkRun->results()->update(['score' => 0.9, 'passed' => true]);
    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id, recheck: true));

    expect($benchmarkRun->fresh()->regressions)->toBe([])
        ->and($benchmarkRun->fresh()->baseline_run_id)->toBe($baseline->id);
    Notification::assertSentTimes(ScheduledBenchmarkAlert::class, $this->adminCount);
});

test('a recheck that finds a new regression alerts about it alone', function (): void {
    regressionRun($this->schedule, $this->case, true);
    $benchmarkRun = regressionRun($this->schedule, $this->case, true);
    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id));

    $benchmarkRun->results()->update(['score' => 0.1, 'passed' => false]);
    dispatch_sync(new CheckBenchmarkRegression($benchmarkRun->id, recheck: true));

    expect($benchmarkRun->fresh()->regressions)->toHaveCount(1);
    Notification::assertSentTimes(ScheduledBenchmarkAlert::class, $this->adminCount);
});

test('a deleted run is ignored', function (): void {
    dispatch_sync(new CheckBenchmarkRegression(999_999));

    Notification::assertNothingSent();
});
