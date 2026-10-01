<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Queue::fake();
    $this->admin = User::factory()->admin()->create(['name' => 'Ada']);
});

test('an admin approves several pending requests; each is approved and executed once', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs($this->admin)
        ->postJson(route('actions.requests.bulk'), ['ids' => $pending->pluck('id')->all(), 'action' => 'approve'])
        ->assertOk()
        ->assertJsonPath('started', 3)
        ->assertJsonPath('toast.message', '3 approved');

    foreach ($pending as $actionRequest) {
        expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Approved)
            ->and($actionRequest->fresh()->approved_by)->toBe($this->admin->id);
    }

    Queue::assertPushed(ExecuteActionRequest::class, 3);
});

test('a request someone else already approved is skipped, not approved again', function (): void {
    $other = User::factory()->admin()->create();
    $stillPending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $approvedMeanwhile = ActionRequest::factory()->create(['status' => ActionRequestStatus::Approved, 'approved_by' => $other->id]);

    $this->actingAs($this->admin)
        ->postJson(route('actions.requests.bulk'), ['ids' => [$stillPending->id, $approvedMeanwhile->id], 'action' => 'approve'])
        ->assertJsonPath('started', 1)
        ->assertJsonPath('skipped', 1)
        ->assertJsonPath('toast.message', '1 approved, 1 skipped');

    expect($approvedMeanwhile->fresh()->approved_by)->toBe($other->id);
    Queue::assertPushed(ExecuteActionRequest::class, fn (ExecuteActionRequest $job): bool => $job->actionRequest->id === $stillPending->id);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
});

test('a shared reason is stored on every rejected request and on its activity row', function (): void {
    $pending = ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs($this->admin)
        ->postJson(route('actions.requests.bulk'), ['ids' => $pending->pluck('id')->all(), 'action' => 'reject', 'reason' => '  Duplicate request  '])
        ->assertJsonPath('started', 2)
        ->assertJsonPath('toast.message', '2 rejected');

    foreach ($pending as $actionRequest) {
        expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Rejected)
            ->and($actionRequest->fresh()->result)->toBe(['rejection_reason' => 'Duplicate request']);
        expect(ActivityLog::query()->where('action', 'action_request.rejected')->where('subject_id', $actionRequest->id)->sole()->description)
            ->toBe(sprintf('Action #%d rejected by Ada: Duplicate request', $actionRequest->id));
    }

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('a 500-character reason rejects every request without breaking the activity log', function (): void {
    $pending = ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);
    $reason = str_repeat('x', 500);

    $this->actingAs($this->admin)
        ->postJson(route('actions.requests.bulk'), ['ids' => $pending->pluck('id')->all(), 'action' => 'reject', 'reason' => $reason])
        ->assertJsonPath('started', 2);

    foreach ($pending as $actionRequest) {
        expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Rejected)
            ->and($actionRequest->fresh()->result)->toBe(['rejection_reason' => $reason]);

        $description = ActivityLog::query()->where('action', 'action_request.rejected')->where('subject_id', $actionRequest->id)->sole()->description;
        expect(mb_strlen($description))->toBeLessThanOrEqual(255);
    }
});

test('a request that no longer exists is reported as failed', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('actions.requests.bulk'), ['ids' => [999], 'action' => 'approve'])
        ->assertJsonPath('failed', [['id' => 999, 'title' => '#999', 'reason' => 'That request no longer exists.']]);
});

test('a request deleted after the preload but before its row lock fails alone, and nothing is dispatched for it', function (): void {
    [$first, $deletedMeanwhile, $third] = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending])->all();

    // Approving the first request deletes the second one, which the bulk
    // endpoint has already preloaded: its row lock then finds nothing.
    ActionRequest::updated(function (ActionRequest $actionRequest) use ($first, $deletedMeanwhile): void {
        if ($actionRequest->id === $first->id) {
            ActionRequest::query()->whereKey($deletedMeanwhile->id)->delete();
        }
    });

    $this->actingAs($this->admin)
        ->postJson(route('actions.requests.bulk'), ['ids' => [$first->id, $deletedMeanwhile->id, $third->id], 'action' => 'approve'])
        ->assertOk()
        ->assertJsonPath('started', 2)
        ->assertJsonPath('failed.0.id', $deletedMeanwhile->id)
        ->assertJsonPath('failed.0.reason', 'That request no longer exists.');

    expect($third->fresh()->status)->toBe(ActionRequestStatus::Approved);
    Queue::assertPushed(ExecuteActionRequest::class, 2);
    Queue::assertNotPushed(ExecuteActionRequest::class, fn (ExecuteActionRequest $job): bool => $job->actionRequest->id === $deletedMeanwhile->id);
});

test('a reason longer than 500 characters or more than 100 ids is refused', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs($this->admin)
        ->postJson(route('actions.requests.bulk'), ['ids' => [$pending->id], 'action' => 'reject', 'reason' => str_repeat('x', 501)])
        ->assertUnprocessable();
    $this->actingAs($this->admin)
        ->postJson(route('actions.requests.bulk'), ['ids' => range(1, 101), 'action' => 'approve'])
        ->assertUnprocessable();

    expect($pending->fresh()->status)->toBe(ActionRequestStatus::Pending);
});

test('members cannot review in bulk', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('actions.requests.bulk'), ['ids' => [$pending->id], 'action' => 'approve'])
        ->assertForbidden();

    expect($pending->fresh()->status)->toBe(ActionRequestStatus::Pending);
});

test('viewers cannot review in bulk', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('actions.requests.bulk'), ['ids' => [$pending->id], 'action' => 'approve'])
        ->assertForbidden();

    expect($pending->fresh()->status)->toBe(ActionRequestStatus::Pending);
});

test('the single decline still stores no reason', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs(User::factory()->member()->create())
        ->post(route('actions.requests.reject', $pending))
        ->assertRedirect();

    expect($pending->fresh()->status)->toBe(ActionRequestStatus::Rejected)
        ->and($pending->fresh()->result)->toBeNull();
});
