<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('viewer sees paginated sanitized escalation summaries', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create(['name' => 'Primary Bazarr']);
    $downloadRequest = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Completed,
    ]);
    $case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'download_action_request_id' => $downloadRequest->id,
        'status' => SubtitleCaseStatus::ReplacementEligible,
        'evidence' => [
            'display_name' => 'Frieren S01E01',
            'missing_languages' => ['eng', 'swe'],
            'current_subtitles' => ['jpn'],
            'monitored' => true,
            'private_path' => '/anime/Frieren/private.mkv',
        ],
        'target_ids' => [
            'series_id' => 100,
            'episode_id' => 200,
            'episode_file_id' => 300,
        ],
        'observed_at' => now()->subDays(2),
    ]);
    SubtitleCaseAttempt::factory()->count(2)->create([
        'subtitle_case_id' => $case->id,
        'type' => SubtitleCaseAttemptType::Probe,
        'outcome' => SubtitleCaseAttemptOutcome::Empty,
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(59),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('bazarr.escalations', ['connection' => $bazarr->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Bazarr/Escalations')
            ->where('can_filter', false)
            ->where('can_investigate', false)
            ->has('cases.data', 1, fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
                ->where('id', $case->id)
                ->where('display_name', 'Frieren S01E01')
                ->where('status', SubtitleCaseStatus::ReplacementEligible->value)
                ->where('scope', 'anime')
                ->where('media_type', 'episode')
                ->where('missing_languages', ['eng', 'swe'])
                ->where('probe_count', 2)
                ->where('download_action_status', ActionRequestStatus::Completed->value)
                ->where('replacement_action_status', null)
                ->hasAll([
                    'first_seen_at',
                    'last_probe_at',
                    'bazarr_connection',
                    'source_connection',
                ])
                ->missingAll(['evidence', 'target_ids', 'file_fingerprint', 'requirements_fingerprint'])
            )
        );

    $serializedProps = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);

    expect($serializedProps)
        ->not->toContain('/anime/Frieren/private.mkv')
        ->not->toContain('"episode_file_id"')
        ->not->toContain($case->file_fingerprint)
        ->not->toContain($case->requirements_fingerprint);
});

test('member sees the phase four investigation affordance', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::ReplacementEligible,
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('bazarr.escalations', ['connection' => $bazarr->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('can_filter', false)
            ->where('can_investigate', true)
        );
});

test('administrator filters escalation status and connection with bounded pagination', function (): void {
    $primaryBazarr = ServiceConnection::factory()->bazarr()->create(['name' => 'Primary Bazarr']);
    $secondaryBazarr = ServiceConnection::factory()->bazarr()->create(['name' => 'Secondary Bazarr']);
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    SubtitleCase::factory()->count(26)->create([
        'bazarr_connection_id' => $primaryBazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::ReplacementEligible,
    ]);
    $expectedCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $primaryBazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::NeedsReview,
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $secondaryBazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::NeedsReview,
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('bazarr.escalations', ['connection' => $primaryBazarr->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('cases.meta.total', 27)
            ->where('cases.meta.last_page', 2)
        );

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('bazarr.escalations', [
            'connection' => $primaryBazarr->id,
            'status' => SubtitleCaseStatus::NeedsReview->value,
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('can_filter', true)
            ->where('filters.connection', $primaryBazarr->id)
            ->where('filters.status', SubtitleCaseStatus::NeedsReview->value)
            ->where('cases.meta.total', 1)
            ->where('cases.meta.per_page', 25)
            ->where('cases.data.0.id', $expectedCase->id)
            ->has('filter_options.statuses', 8)
            ->has('filter_options.connections', 2)
        );
});

test('invalid escalation statuses are rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('bazarr.escalations', ['status' => 'observing']))
        ->assertSessionHasErrors('status');
});
