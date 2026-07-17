<?php

declare(strict_types=1);

use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Jobs\ReconcileSubtitleCase;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Services\Bazarr\BazarrDownloadRequestCreator;
use App\Services\Bazarr\SubtitleCandidateEligibility;
use App\Services\Bazarr\SubtitleCaseLifecycle;
use App\Services\Bazarr\SubtitleCaseReconciler;
use App\Settings\BazarrAutomationSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function probeCandidate(SubtitleCase $subtitleCase): array
{
    return [
        'bazarr_connection_id' => $subtitleCase->bazarr_connection_id,
        'service_connection_id' => $subtitleCase->service_connection_id,
        'service' => 'sonarr',
        'media_type' => $subtitleCase->media_type,
        'scope' => $subtitleCase->scope,
        'target_ids' => $subtitleCase->target_ids,
        'display_name' => $subtitleCase->evidence['display_name'],
        'required_languages' => ['eng'],
        'missing_languages' => ['eng'],
        'current_subtitles' => [],
        'monitored' => true,
        'file_fingerprint' => $subtitleCase->file_fingerprint,
        'requirements_fingerprint' => $subtitleCase->requirements_fingerprint,
    ];
}

beforeEach(function (): void {
    $this->bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'secret',
    ]);
    $this->sonarr = ServiceConnection::factory()->sonarr()->create();
    $this->case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->sonarr->id,
        'status' => SubtitleCaseStatus::BazarrSearching,
        'target_ids' => ['series_id' => 101, 'episode_id' => 701, 'episode_file_id' => 501],
        'required_languages' => [['code' => 'eng', 'forced' => false, 'hearing_impaired' => false]],
        'evidence' => ['display_name' => 'Frieren — Part One', 'missing_languages' => ['eng']],
    ]);
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
    ]);
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_threshold' => 90,
        'global_languages' => ['eng'],
    ]);
});

test('recent probes are not repeated and disabled probe slots still reconcile only', function (bool $probeAllowed): void {
    Http::preventStrayRequests();
    SubtitleCaseAttempt::factory()->for($this->case)->create([
        'type' => SubtitleCaseAttemptType::Probe,
        'started_at' => now()->subHour(),
        'completed_at' => now()->subHour(),
    ]);

    runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case), $probeAllowed));

    Http::assertNothingSent();
    expect(SubtitleCaseAttempt::query()->count())->toBe(1);
})->with([true, false]);

test('empty probes stay searching until the configured threshold then become replacement eligible', function (): void {
    Date::setTestNow('2026-07-17 10:00:00');
    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => []]),
        'bazarr.test/api/providers' => Http::response(['data' => [['name' => 'OpenSubtitles', 'status' => 'healthy']]]),
    ]);

    runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case)));
    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);

    Date::setTestNow('2026-07-18 10:01:00');
    runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case)));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementEligible)
        ->and(SubtitleCaseAttempt::query()->where('outcome', SubtitleCaseAttemptOutcome::Empty)->count())->toBe(2);
});

test('an eligible probe creates one download request and compact attempt summary', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'bazarr_download_best',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);
    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => [[
            'provider' => 'OpenSubtitles',
            'subtitle' => 'stable-download-id',
            'language' => 'eng',
            'forced' => false,
            'hearing_impaired' => false,
            'score' => 95,
            'release_info' => ['Private.Release.Name'],
        ]]]),
        'bazarr.test/api/providers' => Http::response(['data' => [[
            'name' => 'OpenSubtitles',
            'status' => 'healthy',
            'throttled_until' => null,
        ]]]),
    ]);

    $job = new ReconcileSubtitleCase(probeCandidate($this->case));
    runSubtitleProbe($job);
    runSubtitleProbe($job);

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->firstOrFail();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested)
        ->and(ActionRequest::query()->where('type', 'bazarr_download_best')->count())->toBe(1)
        ->and(SubtitleCaseAttempt::query()->count())->toBe(1)
        ->and($subtitleCaseAttempt->candidate_count)->toBe(1)
        ->and($subtitleCaseAttempt->eligible_candidate_count)->toBe(1)
        ->and($subtitleCaseAttempt->summary)->toBe([
            'eligible' => 1,
            'wrong_language' => 0,
            'wrong_qualifier' => 0,
            'provider_unavailable' => 0,
            'below_threshold' => 0,
            'malformed' => 0,
            'capability_limited' => 0,
        ]);

    expect(json_encode($subtitleCaseAttempt->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('Private.Release.Name')
        ->not->toContain('stable-download-id');
});

function runSubtitleProbe(ReconcileSubtitleCase $reconcileSubtitleCase): void
{
    $reconcileSubtitleCase->handle(
        resolve(SubtitleCaseReconciler::class),
        resolve(SubtitleCandidateEligibility::class),
        resolve(BazarrDownloadRequestCreator::class),
        resolve(SubtitleCaseLifecycle::class),
        resolve(BazarrAutomationSettings::class),
        resolve(MediaReplacementSettings::class),
    );
}
