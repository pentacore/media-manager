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

test('a described replacement action request keeps affected files and evidence beside its details', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Pending,
        'requires_approval' => true,
        'type' => 'replace_media_file',
        'source_service' => 'ai',
        'target_service' => 'sonarr',
        'title' => 'Replace Trusted Anime S01E01',
        'description' => 'Sonarr will grab a release with English subtitles.',
        'details' => [
            ['label' => 'Required subtitles', 'value' => 'eng'],
            ['label' => 'Confidence', 'value' => '98%'],
            ['label' => 'Selection', 'value' => 'manual'],
        ],
        'description_verified' => true,
        'payload' => [
            'required_languages' => ['eng'],
            'confidence' => 98,
            'selection_mode' => 'manual',
            'matched_rules' => [['name' => 'Crunchyroll English', 'strength' => 'guarantee']],
            'target' => ['episode_file_ids' => [501, 502]],
        ],
    ]);

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-action-details]', 'Required subtitles')
        ->assertSeeIn('[data-action-details]', '98%')
        ->assertSeeIn('[data-replacement-affected-files]', 'Affected files')
        ->assertSeeIn('[data-replacement-affected-files]', '2')
        ->assertSeeIn('[data-replacement-evidence]', 'Evidence')
        ->assertSeeIn('[data-replacement-evidence]', 'Crunchyroll English');
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
    // failed-only seed labeled "All". The tab is targeted via data-test
    // because its visible text includes the total count ("All 2"), which
    // defeats the exact-text locator click('All') would use.
    visit('/actions/requests?status=failed')
        ->assertNoSmoke()
        ->assertSee('failed.type')
        ->assertDontSee('completed.type')
        ->click('@tab-all')
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

test('a described action request shows its description, details and agent reasoning', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->described()->create([
        'origin' => 'agent',
        'payload' => ['sonarr_series_id' => 142, 'agent_rationale' => 'The series was removed from Emby.'],
    ]);

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-action-title]', 'Delete series "Severance (2022)"')
        ->assertSeeIn('[data-action-description]', 'Sonarr will delete the series and its files from disk.')
        ->assertSeeIn('[data-action-details]', 'Delete files')
        ->assertSeeIn('[data-action-details]', 'Yes')
        ->assertSeeIn('[data-action-ai-reasoning]', 'The series was removed from Emby.')
        ->assertMissing('[data-action-unverified]');
});

test('a system action request does not label its server-written rationale as ai reasoning', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->described()->create([
        'origin' => 'system',
        'payload' => ['sonarr_series_id' => 142, 'agent_rationale' => 'Automatic subtitle check found no English track.'],
    ]);

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-action-description]', 'Sonarr will delete the series and its files from disk.')
        ->assertMissing('[data-action-ai-reasoning]');
});

test('an unverified action request warns that its target name is unverified', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->described()->create(['description_verified' => false]);

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-action-unverified]', 'Target name could not be verified by the server.');
});

test('a legacy action request still shows its payload title', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->create(['payload' => ['title' => 'Legacy title', 'detail' => 'Legacy detail']]);

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-action-title]', 'Legacy title')
        ->assertSeeIn('[data-action-description]', 'Legacy detail');
});
