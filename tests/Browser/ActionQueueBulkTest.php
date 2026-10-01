<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake([ExecuteActionRequest::class]);
});

test('an admin approves two pending requests in bulk', function (): void {
    $first = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"']);
    $second = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Andor"']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn("[data-action-row=\"{$first->id}\"]", 'Severance')
        ->click("[data-bulk-select=\"{$first->id}\"]")
        ->click("[data-bulk-select=\"{$second->id}\"]")
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-approve]')
        ->assertSee('2 approved')
        ->assertNoSmoke();

    expect($first->fresh()->status)->toBe(ActionRequestStatus::Approved)
        ->and($second->fresh()->status)->toBe(ActionRequestStatus::Approved);
});

test('an admin rejects in bulk with one shared reason', function (): void {
    $pending = ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-bulk-select-all]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-reject]')
        ->fill('[data-bulk-reject-reason]', 'Not wanted')
        ->click('[data-bulk-reject-confirm]')
        ->assertSee('2 rejected');

    foreach ($pending as $actionRequest) {
        expect($actionRequest->fresh()->result)->toBe(['rejection_reason' => 'Not wanted']);
    }
});

test('changing the status tab clears the selection', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click("[data-bulk-select=\"{$pending->id}\"]")
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->click('[data-test="tab-pending"]')
        ->assertCount('[data-bulk-bar]', 0);
});

test('a member sees no bulk selection', function (): void {
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertPresent("[data-action-row=\"{$pending->id}\"]")
        ->assertCount('[data-bulk-select]', 0);
});
