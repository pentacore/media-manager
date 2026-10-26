<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
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

test('a batch filter shows only that batch and can be cleared', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->count(2)->create(['payload' => ['subtitle_audit_batch_id' => 7]]);
    ActionRequest::factory()->create(['payload' => ['subtitle_audit_batch_id' => 8]]);

    visit(route('actions.requests.index', ['subtitle_audit_batch' => 7], absolute: false))
        ->assertNoSmoke()
        ->assertPresent('[data-action-batch-filter]')
        ->assertSeeIn('[data-action-batch-filter]', 'Subtitle audit batch #7')
        ->assertCount('[data-action-row]', 2)
        ->click('[data-action-batch-filter-clear]')
        ->assertMissing('[data-action-batch-filter]')
        ->assertCount('[data-action-row]', 3);
});

test('a status tab keeps the batch filter', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending, 'payload' => ['subtitle_audit_batch_id' => 7]]);
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'payload' => ['subtitle_audit_batch_id' => 8]]);

    visit(route('actions.requests.index', ['subtitle_audit_batch' => 7], absolute: false))
        ->assertNoSmoke()
        ->click('[data-test="tab-pending"]')
        ->assertQueryStringHas('subtitle_audit_batch', '7')
        ->assertQueryStringHas('status', 'pending')
        ->assertPresent('[data-action-batch-filter]')
        ->assertCount('[data-action-row]', 2);
});

test('a batch filter lists the episodes left to pick by hand', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $batch = SubtitleAuditBatch::factory()->handedOff()->create(['series_id' => 42, 'series_title' => 'Example Audit Series']);
    $queued = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'payload' => ['subtitle_audit_batch_id' => $batch->id]]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create(['season_number' => 1, 'episode_number' => 1, 'action_request_id' => $queued->id]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->manualPick()->create(['season_number' => 1, 'episode_number' => 4, 'missing_languages' => ['eng']]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->manualPick()->create(['season_number' => 2, 'episode_number' => 6, 'missing_languages' => ['eng']]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->nothingFound()->create(['season_number' => 1, 'episode_number' => 7]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->failed()->create(['season_number' => 1, 'episode_number' => 8]);

    $webpage = visit(route('actions.requests.index', ['subtitle_audit_batch' => $batch->id], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-subtitle-audit-manual-picks]', 'Example Audit Series')
        ->assertCount('[data-subtitle-audit-manual-picks] [data-subtitle-audit-pick]', 2)
        ->assertSeeIn('[data-subtitle-audit-manual-picks]', 'S01E04')
        ->assertSeeIn('[data-subtitle-audit-manual-picks]', 'S02E06')
        ->assertSeeIn('[data-subtitle-audit-misses]', '1 had no usable release, 1 failed.')
        ->assertCount('[data-action-row]', 1);

    expect($webpage->script("document.querySelector('[data-subtitle-audit-pick]').getAttribute('href')"))
        ->toBe(route('media.series.show', ['id' => 42, 'replace_season' => 1, 'replace_episode' => 4], absolute: false));
});

test('a batch with nothing left to pick shows no manual pick section', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $batch = SubtitleAuditBatch::factory()->handedOff()->create();
    $queued = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'payload' => ['subtitle_audit_batch_id' => $batch->id]]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create(['action_request_id' => $queued->id]);

    visit(route('actions.requests.index', ['subtitle_audit_batch' => $batch->id], absolute: false))
        ->assertNoSmoke()
        ->assertPresent('[data-action-batch-filter]')
        ->assertCount('[data-action-row]', 1)
        ->assertMissing('[data-subtitle-audit-manual-picks]');
});

test('a batch filter lists the episodes a pending replacement blocked', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $batch = SubtitleAuditBatch::factory()->handedOff()->create(['series_id' => 42, 'series_title' => 'Example Audit Series']);
    $queued = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'payload' => ['subtitle_audit_batch_id' => $batch->id]]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create(['season_number' => 1, 'episode_number' => 1, 'action_request_id' => $queued->id]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->failed()->create(['season_number' => 1, 'episode_number' => 2, 'failure_reason' => 'replacement_in_flight', 'missing_languages' => ['eng', 'swe']]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->failed()->create(['season_number' => 1, 'episode_number' => 3]);

    visit(route('actions.requests.index', ['subtitle_audit_batch' => $batch->id], absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-subtitle-audit-manual-picks] [data-subtitle-audit-in-flight]', 1)
        ->assertSeeIn('[data-subtitle-audit-in-flight]', 'S01E02')
        ->assertSeeIn('[data-subtitle-audit-in-flight]', 'A replacement is already pending')
        ->assertSeeIn('[data-subtitle-audit-in-flight]', 'missing ENG, SWE')
        ->assertSeeIn('[data-subtitle-audit-misses]', '1 failed.')
        ->assertMissing('[data-subtitle-audit-manual-picks] [data-subtitle-audit-pick]');
});
