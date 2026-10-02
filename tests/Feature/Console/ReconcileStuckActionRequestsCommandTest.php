<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\QueueLane;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\SubtitleCaseAttempt;
use App\Services\Actions\ActionRequestActivityLogger;
use App\Services\Actions\StaleApprovedRequestRedispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestStatusChanged::class]);
});

function reconcileStuckApprovedSince(ActionRequest $actionRequest, int $minutesAgo): void
{
    ActionRequest::query()->whereKey($actionRequest->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);
}

/**
 * True when nothing holds ExecuteActionRequest's unique lock for the request;
 * the probe lock is released again so the check leaves no trace.
 */
function reconcileStuckUniqueLockIsFree(ActionRequest $actionRequest): bool
{
    $probe = new ExecuteActionRequest($actionRequest);
    $uniqueLock = new UniqueLock(Cache::store());

    if (! $uniqueLock->acquire($probe)) {
        return false;
    }

    $uniqueLock->release($probe);

    return true;
}

/**
 * A redispatcher whose Bus push runs $beforePush first and then hands the job
 * to the real (faked-queue) dispatcher.
 */
function reconcileStuckRedispatcherWithPush(Closure $beforePush): StaleApprovedRequestRedispatcher
{
    $realDispatcher = resolve(Dispatcher::class);
    $mock = Mockery::mock(Dispatcher::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(
        static function (ExecuteActionRequest $executeActionRequest) use ($beforePush, $realDispatcher): mixed {
            $beforePush($executeActionRequest);

            return $realDispatcher->dispatch($executeActionRequest);
        },
    );

    return new StaleApprovedRequestRedispatcher(resolve(ActionRequestActivityLogger::class), $mock);
}

test('an action request stuck in executing past the threshold is failed as needs_reconciliation', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);
    ActionRequest::query()->whereKey($actionRequest->id)->update(['updated_at' => now()->subHours(5)]);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    $fresh = $actionRequest->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed)
        ->and($fresh->result['reason'])->toBe('needs_reconciliation')
        ->and($fresh->result['indeterminate'])->toBeTrue()
        ->and($fresh->result['worker_lost'])->toBeTrue();

    Event::assertDispatched(ActionRequestStatusChanged::class);
});

test('a recently-updated executing request is left alone', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Executing);
    Event::assertNotDispatched(ActionRequestStatusChanged::class);
});

test('terminal requests are never touched', function (): void {
    $completed = ActionRequest::factory()->create(['status' => ActionRequestStatus::Completed]);
    ActionRequest::query()->whereKey($completed->id)->update(['updated_at' => now()->subDays(2)]);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    expect($completed->fresh()->status)->toBe(ActionRequestStatus::Completed);
});

test('a needs_reconciliation failure is written to the activity log', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);
    ActionRequest::query()->whereKey($actionRequest->id)->update(['updated_at' => now()->subHours(5)]);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    $activityLog = ActivityLog::query()->where('subject_id', $actionRequest->id)->where('action', 'action_request.failed')->sole();
    expect($activityLog->description)->toContain('needs_reconciliation');
});

test('an approved request whose execution job was lost is handed to the queue again', function (): void {
    Queue::fake();
    $stale = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    reconcileStuckApprovedSince($stale, 45);

    $this->artisan('actions:reconcile-stuck')
        ->expectsOutputToContain('Re-dispatched 1 approved action request(s) that never started.')
        ->assertSuccessful();

    // The job carries the owner of the unique lock acquired for it, so the
    // worker's owner-checked release frees exactly that lock, and it rides the
    // actions lane like any other approved action.
    Queue::assertPushedOn(
        QueueLane::Actions,
        ExecuteActionRequest::class,
        fn (ExecuteActionRequest $executeActionRequest): bool => $executeActionRequest->actionRequest->id === $stale->id
            && is_string($executeActionRequest->uniqueLockOwner)
            && $executeActionRequest->uniqueLockOwner !== '',
    );
    expect($stale->fresh()->status)->toBe(ActionRequestStatus::Approved)
        ->and(reconcileStuckUniqueLockIsFree($stale))->toBeFalse();

    $activityLog = ActivityLog::query()->where('subject_id', $stale->id)->where('action', 'action_request.redispatched')->sole();
    expect($activityLog->description)->toContain((string) $stale->id);
});

test('a re-dispatched job releases its unique lock once it has run', function (): void {
    // Sync queue: the re-dispatched job runs through the real queue handler,
    // which releases the lock with the owner the redispatcher stamped on it.
    Queue::fake()->except([ExecuteActionRequest::class]);
    $stale = ActionRequest::factory()->autoExecute()->create(['type' => 'reconcile_stuck_unregistered_type']);
    reconcileStuckApprovedSince($stale, 45);

    $this->artisan('actions:reconcile-stuck')
        ->expectsOutputToContain('Re-dispatched 1 approved action request(s) that never started.')
        ->assertSuccessful();

    expect($stale->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($stale->fresh()->result['reason'])->toBe('no_executor')
        ->and(reconcileStuckUniqueLockIsFree($stale))->toBeTrue();
});

test('a row that finished between selection and its lock is not re-dispatched', function (): void {
    Queue::fake();
    $first = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    $finishedMeanwhile = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    reconcileStuckApprovedSince($first, 45);
    reconcileStuckApprovedSince($finishedMeanwhile, 45);

    // While the first row is pushed, the second row's backlogged original job
    // claims and completes it, releasing its lock before the loop gets there.
    $staleApprovedRequestRedispatcher = reconcileStuckRedispatcherWithPush(
        static function (ExecuteActionRequest $executeActionRequest) use ($first, $finishedMeanwhile): void {
            if ($executeActionRequest->actionRequest->id === $first->id) {
                ActionRequest::query()->whereKey($finishedMeanwhile->id)->update(['status' => ActionRequestStatus::Completed->value]);
            }
        },
    );

    $staleApprovedReconciliation = $staleApprovedRequestRedispatcher->redispatch(CarbonImmutable::now()->subMinutes(30));

    expect($staleApprovedReconciliation->redispatched)->toBe(1);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
    Queue::assertPushed(ExecuteActionRequest::class, fn (ExecuteActionRequest $executeActionRequest): bool => $executeActionRequest->actionRequest->id === $first->id);
    expect(ActivityLog::query()->where('subject_id', $finishedMeanwhile->id)->where('action', 'action_request.redispatched')->exists())->toBeFalse()
        ->and(reconcileStuckUniqueLockIsFree($finishedMeanwhile))->toBeTrue();
});

test('a failed push releases its lock and the run carries on', function (): void {
    Queue::fake();
    Exceptions::fake();
    $unpushable = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    $next = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    $ancient = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    reconcileStuckApprovedSince($unpushable, 45);
    reconcileStuckApprovedSince($next, 45);
    reconcileStuckApprovedSince($ancient, 25 * 60);

    $staleApprovedRequestRedispatcher = reconcileStuckRedispatcherWithPush(
        static function (ExecuteActionRequest $executeActionRequest) use ($unpushable): void {
            throw_if($executeActionRequest->actionRequest->id === $unpushable->id, RuntimeException::class, 'queue unavailable');
        },
    );

    $staleApprovedReconciliation = $staleApprovedRequestRedispatcher->redispatch(CarbonImmutable::now()->subMinutes(30));

    expect($staleApprovedReconciliation->redispatched)->toBe(1)
        ->and($staleApprovedReconciliation->neverStarted)->toBe(1)
        ->and(reconcileStuckUniqueLockIsFree($unpushable))->toBeTrue()
        ->and($unpushable->fresh()->status)->toBe(ActionRequestStatus::Approved)
        ->and($ancient->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and(ActivityLog::query()->where('subject_id', $unpushable->id)->where('action', 'action_request.redispatched')->exists())->toBeFalse();
    Queue::assertPushed(ExecuteActionRequest::class, fn (ExecuteActionRequest $executeActionRequest): bool => $executeActionRequest->actionRequest->id === $next->id);
    Exceptions::assertReported(fn (RuntimeException $runtimeException): bool => $runtimeException->getMessage() === 'queue unavailable');
});

test('advisor attempts for every stale advisor replacement are read in one query', function (): void {
    Queue::fake();
    $replacements = ActionRequest::factory()->autoExecute()->count(3)->create([
        'type' => 'replace_media_file',
        'source_service' => 'subtitle_advisor',
        'payload' => ['subtitle_case_id' => 1],
    ]);
    SubtitleCaseAttempt::factory()->create([
        'action_request_id' => $replacements->first()->id,
        'type' => SubtitleCaseAttemptType::Advisor,
        'outcome' => SubtitleCaseAttemptOutcome::Succeeded,
        'completed_at' => now(),
    ]);
    $replacements->each(fn (ActionRequest $actionRequest) => reconcileStuckApprovedSince($actionRequest, 45));
    $attemptQueries = new stdClass;
    $attemptQueries->count = 0;
    DB::listen(static function (QueryExecuted $queryExecuted) use ($attemptQueries): void {
        if (str_contains($queryExecuted->sql, 'subtitle_case_attempts')) {
            $attemptQueries->count++;
        }
    });

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    expect($attemptQueries->count)->toBe(1);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
});

test('a recently approved request is left to its own job', function (): void {
    Queue::fake();
    $recent = ActionRequest::factory()->autoExecute()->create();
    reconcileStuckApprovedSince($recent, 10);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('the approved threshold follows the option', function (): void {
    Queue::fake();
    $request = ActionRequest::factory()->autoExecute()->create();
    reconcileStuckApprovedSince($request, 45);

    $this->artisan('actions:reconcile-stuck', ['--approved-minutes' => 90])->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('an emby scan still inside its debounce window is not started early', function (): void {
    Queue::fake();
    $scan = ActionRequest::factory()->autoExecute()->create([
        'type' => 'emby_library_scan',
        'payload' => ['trigger' => 'sonarr_download', 'scan_after' => now()->addMinutes(5)->toIso8601String()],
    ]);
    reconcileStuckApprovedSince($scan, 45);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('an emby scan whose debounce window passed long ago is re-dispatched', function (): void {
    Queue::fake();
    $scan = ActionRequest::factory()->autoExecute()->create([
        'type' => 'emby_library_scan',
        'payload' => ['trigger' => 'sonarr_download', 'scan_after' => now()->subMinutes(44)->toIso8601String()],
    ]);
    reconcileStuckApprovedSince($scan, 45);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertPushed(ExecuteActionRequest::class, 1);
});

test('a subtitle advisor replacement the advisor never finalized is not started', function (): void {
    Queue::fake();
    $replacement = ActionRequest::factory()->autoExecute()->create([
        'type' => 'replace_media_file',
        'source_service' => 'subtitle_advisor',
        'payload' => ['subtitle_case_id' => 1],
    ]);
    reconcileStuckApprovedSince($replacement, 45);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('a finalized subtitle advisor replacement whose job was lost is re-dispatched', function (): void {
    Queue::fake();
    $replacement = ActionRequest::factory()->autoExecute()->create([
        'type' => 'replace_media_file',
        'source_service' => 'subtitle_advisor',
        'payload' => ['subtitle_case_id' => 1],
    ]);
    SubtitleCaseAttempt::factory()->create([
        'action_request_id' => $replacement->id,
        'type' => SubtitleCaseAttemptType::Advisor,
        'outcome' => SubtitleCaseAttemptOutcome::Succeeded,
        'completed_at' => now(),
    ]);
    reconcileStuckApprovedSince($replacement, 45);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertPushed(ExecuteActionRequest::class, 1);
});

test('a request whose job is still queued is not queued a second time', function (): void {
    Queue::fake();
    $request = ActionRequest::factory()->autoExecute()->create();
    // The original job is still waiting in a backed-up queue: it holds the
    // ShouldBeUnique lock, so the reconcile dispatch must be dropped.
    dispatch(new ExecuteActionRequest($request));
    reconcileStuckApprovedSince($request, 45);

    $this->artisan('actions:reconcile-stuck')
        ->expectsOutputToContain('Re-dispatched 0 approved action request(s) that never started.')
        ->assertSuccessful();

    Queue::assertPushed(ExecuteActionRequest::class, 1);
    expect(ActivityLog::query()->where('subject_id', $request->id)->where('action', 'action_request.redispatched')->exists())->toBeFalse();
});

test('pending requests are never dispatched by the reconcile', function (): void {
    Queue::fake();
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    reconcileStuckApprovedSince($pending, 600);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('an approved request older than 24 hours is failed as needs_reconciliation instead of redispatched', function (): void {
    Queue::fake();
    $ancient = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    reconcileStuckApprovedSince($ancient, 25 * 60);

    $this->artisan('actions:reconcile-stuck')
        ->expectsOutputToContain('Failed 1 approved action request(s) older than 24 hours as needs_reconciliation.')
        ->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);

    $fresh = $ancient->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed)
        ->and($fresh->result['reason'])->toBe('needs_reconciliation')
        ->and($fresh->result['indeterminate'])->toBeFalse()
        ->and($fresh->result['never_started'])->toBeTrue();

    $activityLog = ActivityLog::query()->where('subject_id', $ancient->id)->where('action', 'action_request.failed')->sole();
    expect($activityLog->description)->toContain('needs_reconciliation');
    Event::assertDispatched(ActionRequestStatusChanged::class);
});

test('an emby scan still inside its debounce window is left waiting even past the 24 hour bound', function (): void {
    Queue::fake();
    $scan = ActionRequest::factory()->autoExecute()->create([
        'type' => 'emby_library_scan',
        'payload' => ['trigger' => 'sonarr_download', 'scan_after' => now()->addMinutes(5)->toIso8601String()],
    ]);
    reconcileStuckApprovedSince($scan, 25 * 60);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
    expect($scan->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('an unfinalized subtitle advisor replacement past the 24 hour bound is failed as never started', function (): void {
    // Nothing finalizes an advisor replacement after its own job: one still
    // unfinalized a day later would otherwise sit Approved forever.
    Queue::fake();
    $replacement = ActionRequest::factory()->autoExecute()->create([
        'type' => 'replace_media_file',
        'source_service' => 'subtitle_advisor',
        'payload' => ['subtitle_case_id' => 1],
    ]);
    reconcileStuckApprovedSince($replacement, 25 * 60);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
    $fresh = $replacement->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed)
        ->and($fresh->result['reason'])->toBe('needs_reconciliation')
        ->and($fresh->result['never_started'])->toBeTrue();
});

test('the approved-minutes option floors below five minutes', function (): void {
    Queue::fake();
    $request = ActionRequest::factory()->autoExecute()->create();
    reconcileStuckApprovedSince($request, 4);

    $this->artisan('actions:reconcile-stuck', ['--approved-minutes' => 1])->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('the approved-minutes option cannot exceed the 24 hour age bound', function (): void {
    Queue::fake();
    $ancient = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    reconcileStuckApprovedSince($ancient, 25 * 60);

    $this->artisan('actions:reconcile-stuck', ['--approved-minutes' => 100_000])->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
    expect($ancient->fresh()->status)->toBe(ActionRequestStatus::Failed);
});
