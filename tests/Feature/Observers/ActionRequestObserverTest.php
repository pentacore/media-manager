<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActivityLogCreated;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionRequestBrowserPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

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

test('a request the second opinion created rejected is logged as rejected, never as auto-execute', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Rejected,
        'requires_approval' => false,
        'result' => ['success' => false, 'reason' => 'second_opinion_rejected', 'rejection_reason' => 'Rejected by second opinion: Wrong series.'],
    ]);

    $log = ActivityLog::where('subject_id', $request->id)->sole();

    expect($log->action)->toBe('action_request.created')
        ->and($log->description)->toContain('rejected by second opinion')->not->toContain('auto-execute')
        ->and($log->metadata['status'])->toBe('rejected');
});

test('a request created rejected for any other reason gets no second-opinion label', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Rejected, 'requires_approval' => true]);

    expect(ActivityLog::where('subject_id', $request->id)->sole()->description)
        ->not->toContain('second opinion')
        ->toContain('requires approval');
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
        'rejection_reason' => null,
    ]);
    expect($log->metadata['result'])->not->toHaveKey('message');
    expect($log->metadata['result'])->not->toHaveKey('exception');
});

test('stored metadata.result is the member-safe result the browser reads', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $created = ActivityLog::where('action', 'action_request.created')
        ->where('subject_id', $actionRequest->id)
        ->sole();

    $actionRequest->update([
        'status' => ActionRequestStatus::Rejected,
        'result' => ['success' => false, 'reason' => 'rejected', 'rejection_reason' => 'Not this one.', 'message' => 'internal'],
    ]);

    $rejected = ActivityLog::where('action', 'action_request.rejected')
        ->where('subject_id', $actionRequest->id)
        ->sole();

    expect($created->metadata['result'])->toBeNull()
        ->and($rejected->metadata['result'])->toEqual(resolve(ActionRequestBrowserPayload::class)->safeResult($actionRequest->fresh()))
        ->and($rejected->metadata['result'])->toEqual([
            'success' => false,
            'reason' => 'rejected',
            'rejection_reason' => 'Not this one.',
        ]);
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

test('a status change made inside a transaction is logged only when the transaction commits', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $holder = new stdClass;

    DB::transaction(function () use ($request, $holder): void {
        $request->update(['status' => ActionRequestStatus::Approved]);
        $holder->approvedRowsBeforeCommit = ActivityLog::query()
            ->where('subject_id', $request->id)
            ->where('action', 'action_request.approved')
            ->count();
    });

    expect($holder->approvedRowsBeforeCommit)->toBe(0)
        ->and(ActivityLog::query()->where('subject_id', $request->id)->where('action', 'action_request.approved')->count())->toBe(1);
});

test('a rolled-back create or status change writes and broadcasts nothing', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $rowsBefore = ActivityLog::query()->count();
    Event::fake([ActivityLogCreated::class]);

    expect(fn () => DB::transaction(function () use ($pending): void {
        ActionRequest::factory()->create(['webhook_event_id' => null]);
        $pending->update(['status' => ActionRequestStatus::Rejected]);

        throw new RuntimeException('roll back');
    }))->toThrow(RuntimeException::class, 'roll back');

    expect(ActivityLog::query()->count())->toBe($rowsBefore);
    Event::assertNotDispatched(ActivityLogCreated::class);
});

test('a failing activity write never stops the after-commit callbacks queued after it', function (
    string $transition,
): void {
    Exceptions::fake();
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    ActivityLog::creating(static function (): never {
        throw new RuntimeException('activity write failed');
    });
    $holder = new stdClass;
    $holder->jobPushRan = false;

    DB::transaction(function () use ($transition, $pending, $holder): void {
        if ($transition === 'created') {
            ActionRequest::factory()->create(['webhook_event_id' => null]);
        } else {
            $pending->update(['status' => ActionRequestStatus::Approved]);
        }

        // Stands in for ActionOrchestrator's dispatch(...)->afterCommit() push.
        DB::afterCommit(static function () use ($holder): void {
            $holder->jobPushRan = true;
        });
    });

    expect($holder->jobPushRan)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $runtimeException): bool => $runtimeException->getMessage() === 'activity write failed');
})->with([
    'created' => ['created'],
    'status change' => ['updated'],
]);
