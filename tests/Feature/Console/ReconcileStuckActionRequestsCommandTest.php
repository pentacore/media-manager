<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\SubtitleCaseAttempt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestStatusChanged::class]);
});

function reconcileStuckApprovedSince(ActionRequest $actionRequest, int $minutesAgo): void
{
    ActionRequest::query()->whereKey($actionRequest->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);
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

    Queue::assertPushed(ExecuteActionRequest::class, fn (ExecuteActionRequest $executeActionRequest): bool => $executeActionRequest->actionRequest->id === $stale->id);
    expect($stale->fresh()->status)->toBe(ActionRequestStatus::Approved);

    $activityLog = ActivityLog::query()->where('subject_id', $stale->id)->where('action', 'action_request.redispatched')->sole();
    expect($activityLog->description)->toContain((string) $stale->id);
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

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

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

test('an unfinalized subtitle advisor replacement is left waiting even past the 24 hour bound', function (): void {
    Queue::fake();
    $replacement = ActionRequest::factory()->autoExecute()->create([
        'type' => 'replace_media_file',
        'source_service' => 'subtitle_advisor',
        'payload' => ['subtitle_case_id' => 1],
    ]);
    reconcileStuckApprovedSince($replacement, 25 * 60);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    Queue::assertNotPushed(ExecuteActionRequest::class);
    expect($replacement->fresh()->status)->toBe(ActionRequestStatus::Approved);
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
