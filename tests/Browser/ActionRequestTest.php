<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\User;

test('a member sees a pending action request and can approve it', function (): void {
    $member = User::factory()->member()->create();
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Pending,
        'requires_approval' => true,
        'type' => 'sonarr.scan',
    ]);

    $this->actingAs($member);

    visit('/actions/requests')
        ->assertNoJavaScriptErrors()
        ->assertSee('Action Requests')
        ->assertSee('sonarr.scan')
        ->assertSee('pending')
        ->click('Approve')
        ->assertPathIs('/actions/requests');

    // Sync queue runs the dispatched action immediately. Without a real
    // Sonarr to talk to it lands in Failed — the relevant assertion is
    // that the click moved the request out of Pending.
    expect($request->fresh()->status)->not->toBe(ActionRequestStatus::Pending);
});

test('a completed action request renders with a non-pending status', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Completed,
        'type' => 'sonarr.scan',
        'requires_approval' => false,
    ]);

    $this->actingAs($member);

    visit('/actions/requests')
        ->assertNoJavaScriptErrors()
        ->assertSee('sonarr.scan')
        ->assertSee('completed');
});

test('a viewer cannot reach the action requests page', function (): void {
    $viewer = User::factory()->create(); // default Viewer role

    $this->actingAs($viewer);

    // role:member middleware aborts with 403; the page never renders, so
    // the member-only "Action Requests" heading must not appear.
    visit('/actions/requests')->assertDontSee('Action Requests');
});
