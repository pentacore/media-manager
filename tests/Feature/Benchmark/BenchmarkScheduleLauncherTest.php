<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkScheduleOutcome;
use App\Jobs\RunBenchmarkCase;
use App\Models\ActivityLog;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Models\User;
use App\Notifications\ScheduledBenchmarkAlert;
use App\Services\Benchmark\BenchmarkScheduleLauncher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(3, 2));
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-tier', 'input_per_mtok' => 2, 'output_per_mtok' => 8]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-judge', 'input_per_mtok' => 1, 'output_per_mtok' => 4]);
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-tier')->create();
    User::factory()->admin()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function dueTitleSchedule(array $attributes = []): BenchmarkSchedule
{
    return BenchmarkSchedule::factory()->forTask(AiTask::Title)->due()->create($attributes);
}

test('a due schedule launches a run against the task tiers and advances once', function (): void {
    $benchmarkSchedule = dueTitleSchedule(['name' => 'Nightly titles', 'cost_cap_usd' => 5]);
    $case = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    BenchmarkCase::factory()->create(); // a Decision case, not this schedule's task

    $attempt = resolve(BenchmarkScheduleLauncher::class)->launchDue($benchmarkSchedule->id);

    $benchmarkRun = $attempt->benchmarkRun;
    $benchmarkSchedule->refresh();

    expect($attempt->outcome)->toBe(BenchmarkScheduleOutcome::Launched)
        ->and($benchmarkRun->name)->toBe('Nightly titles · 2026-10-20')
        ->and($benchmarkRun->benchmark_schedule_id)->toBe($benchmarkSchedule->id)
        ->and($benchmarkRun->case_ids)->toBe([$case->id])
        ->and(array_column($benchmarkRun->candidates, 'model'))->toBe(['gpt-tier'])
        ->and($benchmarkRun->judge)->toBe($benchmarkSchedule->judge)
        ->and((float) $benchmarkRun->cost_cap_usd)->toBe(5.0)
        ->and($benchmarkRun->created_by)->toBe($benchmarkSchedule->created_by)
        ->and($benchmarkSchedule->last_outcome)->toBe(BenchmarkScheduleOutcome::Launched)
        ->and($benchmarkSchedule->last_run_at)->not->toBeNull()
        ->and($benchmarkSchedule->next_run_at->isFuture())->toBeTrue()
        ->and($benchmarkSchedule->next_run_at->format('Y-m-d H:i'))->toBe('2026-10-21 03:00');

    Queue::assertPushed(RunBenchmarkCase::class, 1);
    Notification::assertNothingSent();
    expect(ActivityLog::query()->where('action', 'benchmark_run.started')->first()->metadata['context'])->toMatchArray(['scheduled' => true, 'schedule_id' => $benchmarkSchedule->id]);
});

test('a schedule overdue by days launches once and lands its next run in the future', function (): void {
    $benchmarkSchedule = dueTitleSchedule(['next_run_at' => now()->subDays(5)]);
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $benchmarkScheduleLauncher = resolve(BenchmarkScheduleLauncher::class);
    $first = $benchmarkScheduleLauncher->launchDue($benchmarkSchedule->id);
    $second = $benchmarkScheduleLauncher->launchDue($benchmarkSchedule->id);

    expect($first->outcome)->toBe(BenchmarkScheduleOutcome::Launched)
        ->and($second)->toBeNull()
        ->and(BenchmarkRun::query()->count())->toBe(1)
        ->and($benchmarkSchedule->fresh()->next_run_at->isFuture())->toBeTrue();
});

test('a schedule that is not due, disabled or gone does nothing', function (): void {
    $notDue = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create(['next_run_at' => now()->addHour()]);
    $disabled = BenchmarkSchedule::factory()->forTask(AiTask::Title)->disabled()->create();
    $benchmarkScheduleLauncher = resolve(BenchmarkScheduleLauncher::class);

    expect($benchmarkScheduleLauncher->launchDue($notDue->id))->toBeNull()
        ->and($benchmarkScheduleLauncher->launchDue($disabled->id))->toBeNull()
        ->and($benchmarkScheduleLauncher->launchDue(999_999))->toBeNull()
        ->and(BenchmarkRun::query()->count())->toBe(0);
});

test('tags narrow the cases to any of them', function (): void {
    $benchmarkSchedule = dueTitleSchedule(['case_tags' => ['anime', 'movies']]);
    $anime = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['tags' => ['anime']]);
    $movies = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['tags' => ['movies', 'hd']]);
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['tags' => ['tv']]);

    $attempt = resolve(BenchmarkScheduleLauncher::class)->launchDue($benchmarkSchedule->id);

    expect($attempt->benchmarkRun->case_ids)->toBe([$anime->id, $movies->id]);
});

test('no matching cases skips and notifies admins', function (): void {
    $benchmarkSchedule = dueTitleSchedule(['case_tags' => ['nothing']]);

    $attempt = resolve(BenchmarkScheduleLauncher::class)->launchDue($benchmarkSchedule->id);

    expect($attempt->outcome)->toBe(BenchmarkScheduleOutcome::SkippedNoCases)
        ->and($benchmarkSchedule->fresh()->last_outcome)->toBe(BenchmarkScheduleOutcome::SkippedNoCases)
        ->and($benchmarkSchedule->fresh()->last_message)->toBe('No Conversation titles cases carry the tags nothing.')
        ->and(BenchmarkRun::query()->count())->toBe(0);
    Notification::assertSentTo(User::query()->where('role', 'admin')->first(), ScheduledBenchmarkAlert::class, fn (ScheduledBenchmarkAlert $alert): bool => $alert->kind === 'skipped');
});

test('a running previous run skips', function (): void {
    $benchmarkSchedule = dueTitleSchedule();
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->running()->create(['name' => 'Still going']);

    $attempt = resolve(BenchmarkScheduleLauncher::class)->launchDue($benchmarkSchedule->id);

    expect($attempt->outcome)->toBe(BenchmarkScheduleOutcome::SkippedRunning)
        ->and($attempt->message)->toBe('The previous run "Still going" is still going.')
        ->and(BenchmarkRun::query()->count())->toBe(1);
});

test('an estimate over the cap skips without creating a run', function (): void {
    $benchmarkSchedule = dueTitleSchedule(['cost_cap_usd' => 0.000001]);
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $attempt = resolve(BenchmarkScheduleLauncher::class)->launchDue($benchmarkSchedule->id);

    expect($attempt->outcome)->toBe(BenchmarkScheduleOutcome::SkippedOverCap)
        ->and($attempt->message)->toStartWith('The estimate $')
        ->and($attempt->message)->toContain('is over the $')
        ->and(BenchmarkRun::query()->count())->toBe(0);
    Notification::assertSentTimes(ScheduledBenchmarkAlert::class, User::query()->where('role', 'admin')->count());
});

test('an exception records a fixed failure and still advances the schedule', function (): void {
    $benchmarkSchedule = dueTitleSchedule();
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    // A task removed from AiTask since the schedule was saved: reading it throws inside the attempt.
    DB::table('benchmark_schedules')->where('id', $benchmarkSchedule->id)->update(['task' => 'retired_task']);

    $attempt = resolve(BenchmarkScheduleLauncher::class)->launchDue($benchmarkSchedule->id);

    expect($attempt->outcome)->toBe(BenchmarkScheduleOutcome::Failed)
        ->and($benchmarkSchedule->fresh()->last_message)->toBe(BenchmarkScheduleLauncher::FAILED_MESSAGE)
        ->and($benchmarkSchedule->fresh()->next_run_at->isFuture())->toBeTrue();
    Notification::assertSentTimes(ScheduledBenchmarkAlert::class, User::query()->where('role', 'admin')->count());
});

test('a deleted creator leaves the scheduled run unattributed', function (): void {
    $benchmarkSchedule = dueTitleSchedule();
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $benchmarkSchedule->creator->delete();

    $attempt = resolve(BenchmarkScheduleLauncher::class)->launchDue($benchmarkSchedule->id);

    expect($attempt->benchmarkRun->created_by)->toBeNull();
});

test('run now launches for the clicking admin, even when disabled, without moving the next run', function (): void {
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->disabled()->create();
    BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();
    $admin = User::factory()->admin()->create();

    $benchmarkScheduleAttempt = resolve(BenchmarkScheduleLauncher::class)->launchNow($benchmarkSchedule, $admin);

    expect($benchmarkScheduleAttempt->outcome)->toBe(BenchmarkScheduleOutcome::Launched)
        ->and($benchmarkScheduleAttempt->benchmarkRun->created_by)->toBe($admin->id)
        ->and($benchmarkSchedule->fresh()->next_run_at)->toBeNull();
    Notification::assertNothingSent();
});

test('run now skips without notifying', function (): void {
    $benchmarkSchedule = BenchmarkSchedule::factory()->forTask(AiTask::Title)->create();

    $benchmarkScheduleAttempt = resolve(BenchmarkScheduleLauncher::class)->launchNow($benchmarkSchedule, User::factory()->admin()->create());

    expect($benchmarkScheduleAttempt->outcome)->toBe(BenchmarkScheduleOutcome::SkippedNoCases)
        ->and($benchmarkScheduleAttempt->message)->toBe('No Conversation titles cases match this schedule.');
    Notification::assertNothingSent();
});
