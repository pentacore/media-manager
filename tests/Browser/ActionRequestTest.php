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
        ->assertNoSmoke()
        ->assertSee('Action queue')
        ->assertSee('sonarr.scan')
        ->assertSee('Pending')
        ->click('Approve & execute')
        ->assertPathIs('/actions/requests');

    // Sync queue runs the dispatched action immediately against the
    // factory's loopback URL (nothing listens there), so the execution
    // deterministically fails — assert the concrete terminal state rather
    // than merely "not pending", which a stray 2xx would also satisfy.
    expect($request->fresh()->status)->toBe(ActionRequestStatus::Failed);
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
        ->assertNoSmoke()
        ->assertSee('sonarr.scan')
        ->assertSee('Completed');
});

test('a replacement action request shows subtitle evidence in the detail panel', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Pending,
        'requires_approval' => true,
        'type' => 'replace_media_file',
        'source_service' => 'ai',
        'target_service' => 'sonarr',
        'payload' => [
            'title' => 'Replace Trusted Anime S01E01',
            'detail' => 'Current file has no English subtitles.',
            'scope' => 'anime',
            'required_languages' => ['eng'],
            'confidence' => 98,
            'selection_mode' => 'manual',
            'matched_rules' => [['name' => 'Crunchyroll English', 'strength' => 'guarantee']],
            'candidate' => ['season_pack' => true],
            'target' => ['episode_file_ids' => [501, 502]],
        ],
    ]);

    $this->actingAs($member);

    visit('/actions/requests')
        ->assertNoSmoke()
        ->assertSee('Required subtitles')
        ->assertSee('Confidence')
        ->assertSee('98%')
        ->assertSee('Selection')
        ->assertSee('manual')
        ->assertSee('Crunchyroll English')
        ->assertSee('season pack');
});

test('switching from a filtered tab back to All renders the unfiltered rows', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Failed,
        'type' => 'failed.type',
        'requires_approval' => false,
    ]);
    ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Completed,
        'type' => 'completed.type',
        'requires_approval' => false,
    ]);

    $this->actingAs($member);

    // Land directly on the Failed filter (seeds the realtime list with
    // failed rows only), then click All: preserveState keeps the component
    // alive, so without reseeding the table would keep showing the
    // failed-only seed labeled "All".
    visit('/actions/requests?status=failed')
        ->assertNoSmoke()
        ->assertSee('failed.type')
        ->assertDontSee('completed.type')
        ->click('All')
        ->assertSee('completed.type')
        ->assertSee('failed.type');
});

test('a viewer cannot reach the action requests page', function (): void {
    $viewer = User::factory()->create(); // default Viewer role

    $this->actingAs($viewer);

    // role:member middleware aborts with 403; the page never renders, so
    // the member-only "Action Requests" heading must not appear.
    visit('/actions/requests')->assertDontSee('Action Requests');
});
