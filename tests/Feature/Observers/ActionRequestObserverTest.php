<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;

test('logs ActivityLog entry when an ActionRequest is created', function (): void {
    ActionRequest::factory()->create([
        'type' => 'delete_series',
        'source_service' => 'emby',
        'target_service' => 'sonarr',
        'requires_approval' => true,
    ]);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'action_request.created',
        'subject_type' => ActionRequest::class,
    ]);
});

test('description mentions approval requirement', function (): void {
    $request = ActionRequest::factory()->create(['requires_approval' => true]);

    $log = ActivityLog::where('action', 'action_request.created')
        ->where('subject_id', $request->id)
        ->first();

    expect($log->description)->toContain('requires approval');
});

test('description for auto-execute shows auto-execute tag', function (): void {
    $request = ActionRequest::factory()->autoExecute()->create();

    $log = ActivityLog::where('action', 'action_request.created')
        ->where('subject_id', $request->id)
        ->first();

    expect($log->description)->toContain('auto-execute');
});

test('logs approval transition with approver name', function (): void {
    $approver = User::factory()->create(['name' => 'Alice']);
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $request->update([
        'status' => ActionRequestStatus::Approved,
        'approved_by' => $approver->id,
    ]);

    $log = ActivityLog::where('action', 'action_request.approved')
        ->where('subject_id', $request->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Alice');
    expect($log->user_id)->toBe($approver->id);
});

test('logs rejection transition', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $request->update(['status' => ActionRequestStatus::Rejected]);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'action_request.rejected',
        'subject_id' => $request->id,
    ]);
});

test('logs executing transition', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Approved]);

    $request->update(['status' => ActionRequestStatus::Executing]);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'action_request.executing',
        'subject_id' => $request->id,
    ]);
});

test('logs completed transition', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);

    $request->update(['status' => ActionRequestStatus::Completed]);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'action_request.completed',
        'subject_id' => $request->id,
    ]);
});

test('logs failed transition with reason from result', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);

    $request->update([
        'status' => ActionRequestStatus::Failed,
        'result' => ['success' => false, 'reason' => 'execution_failed', 'message' => 'boom'],
    ]);

    $log = ActivityLog::where('action', 'action_request.failed')
        ->where('subject_id', $request->id)
        ->first();

    expect($log->description)->toContain('execution_failed');
    // The description must never include the raw exception message which can
    // leak sensitive server-internal detail.
    expect($log->description)->not->toContain('boom');
});

test('stored metadata.result is narrowed to safe fields only', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);

    $request->update([
        'status' => ActionRequestStatus::Failed,
        'result' => [
            'success' => false,
            'reason' => 'retries_exhausted',
            'message' => 'SQLSTATE: sensitive path /var/www/config.php',
            'exception' => QueryException::class,
        ],
    ]);

    $log = ActivityLog::where('action', 'action_request.failed')
        ->where('subject_id', $request->id)
        ->first();

    expect($log->metadata['result'])->toEqual([
        'success' => false,
        'reason' => 'retries_exhausted',
    ]);
    expect($log->metadata['result'])->not->toHaveKey('message');
    expect($log->metadata['result'])->not->toHaveKey('exception');
});

test('logs failed transition without a reason falls back to unknown', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);

    $request->update([
        'status' => ActionRequestStatus::Failed,
        'result' => ['success' => false, 'message' => 'raw boom would have leaked'],
    ]);

    $log = ActivityLog::where('action', 'action_request.failed')
        ->where('subject_id', $request->id)
        ->first();

    expect($log->description)->toContain('unknown reason');
    expect($log->description)->not->toContain('raw boom');
});

test('does not log on non-status updates', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $createLogsCount = ActivityLog::count();

    $request->update(['payload' => ['updated' => true]]);

    expect(ActivityLog::count())->toBe($createLogsCount);
});

test('metadata includes type source target status', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'source_service' => 'emby',
        'target_service' => 'sonarr',
    ]);

    $log = ActivityLog::where('subject_id', $request->id)->first();

    expect($log->metadata)->toMatchArray([
        'type' => 'delete_series',
        'source_service' => 'emby',
        'target_service' => 'sonarr',
    ]);
});

test('service_connection_id populated from webhook event when available', function (): void {
    $webhookEvent = WebhookEvent::factory()->create();
    $request = ActionRequest::factory()->create(['webhook_event_id' => $webhookEvent->id]);

    $log = ActivityLog::where('subject_id', $request->id)->first();

    expect($log->service_connection_id)->toBe($webhookEvent->service_connection_id);
});
