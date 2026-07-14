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
        ->assertSee('Action queue')
        ->assertSee('sonarr.scan')
        ->assertSee('Pending')
        ->click('Approve & execute')
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
        ->assertNoJavaScriptErrors()
        ->assertSee('Required subtitles')
        ->assertSee('Confidence')
        ->assertSee('98%')
        ->assertSee('Selection')
        ->assertSee('manual')
        ->assertSee('Crunchyroll English')
        ->assertSee('season pack');
});

test('a viewer cannot reach the action requests page', function (): void {
    $viewer = User::factory()->create(); // default Viewer role

    $this->actingAs($viewer);

    // role:member middleware aborts with 403; the page never renders, so
    // the member-only "Action Requests" heading must not appear.
    visit('/actions/requests')->assertDontSee('Action Requests');
});
