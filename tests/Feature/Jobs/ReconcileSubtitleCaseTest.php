<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Jobs\ReconcileSubtitleCase;
use App\Jobs\RunSubtitleAdvisor;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Services\Bazarr\BazarrDownloadRequestCreator;
use App\Services\Bazarr\BazarrSettingsAdapter;
use App\Services\Bazarr\SubtitleCandidateEligibility;
use App\Services\Bazarr\SubtitleCaseLifecycle;
use App\Services\Bazarr\SubtitleCaseReconciler;
use App\Services\Bazarr\SubtitleInventoryService;
use App\Settings\BazarrAutomationSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
    Cache::flush();
    config(['mediamanager.ai.enabled' => false]);
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

test('newly eligible cases dispatch Advisor work only up to the cycle cap', function (): void {
    Date::setTestNow('2026-07-17 10:00:00');
    config(['mediamanager.ai.enabled' => true]);
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
        'max_advisor_escalations_per_cycle' => 1,
    ]);
    $secondCase = $this->case->replicate()->fill([
        'file_fingerprint' => hash('sha256', 'second-file'),
        'requirements_fingerprint' => hash('sha256', 'second-requirements'),
    ]);
    $secondCase->save();

    foreach ([$this->case, $secondCase] as $subtitleCase) {
        SubtitleCaseAttempt::factory()->for($subtitleCase)->create([
            'type' => SubtitleCaseAttemptType::Probe,
            'outcome' => SubtitleCaseAttemptOutcome::Empty,
            'started_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2),
        ]);
    }

    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => []]),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);
    Queue::fake([RunSubtitleAdvisor::class]);

    runSubtitleProbe(ReconcileSubtitleCase::forCase($this->case));
    runSubtitleProbe(ReconcileSubtitleCase::forCase($secondCase));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementEligible)
        ->and($secondCase->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementEligible);
    Queue::assertPushed(RunSubtitleAdvisor::class, 1);

    Cache::forget('bazarr-advisor-cycle-count:'.$this->bazarr->id);
    runSubtitleProbe(ReconcileSubtitleCase::forCase($secondCase));

    Queue::assertPushed(RunSubtitleAdvisor::class, 2);
    Queue::assertPushed(
        RunSubtitleAdvisor::class,
        fn (RunSubtitleAdvisor $runSubtitleAdvisor): bool => $runSubtitleAdvisor->subtitleCaseId === $secondCase->id,
    );
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

test('a transient probe failure records the attempt and stays retryable', function (): void {
    Http::fake([
        'bazarr.test/api/providers/episodes*' => fn (): never => throw new ConnectionException('bazarr unreachable'),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);
    $reconcileSubtitleCase = new ReconcileSubtitleCase(probeCandidate($this->case));

    // The job is configured with three tries and a 60/300 second backoff, so a
    // brief Bazarr or provider outage must surface as a failure the queue can
    // retry rather than permanently parking the case.
    expect(function () use ($reconcileSubtitleCase): void {
        runSubtitleProbe($reconcileSubtitleCase);
    })->toThrow(ConnectionException::class);

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and(SubtitleCaseAttempt::query()->where('type', SubtitleCaseAttemptType::Probe)->firstOrFail()->outcome)
        ->toBe(SubtitleCaseAttemptOutcome::Failed);
});

test('the last probe attempt parks the case for review', function (): void {
    Http::fake([
        'bazarr.test/api/providers/episodes*' => fn (): never => throw new ConnectionException('bazarr unreachable'),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);
    $reconcileSubtitleCase = new ReconcileSubtitleCase(probeCandidate($this->case));

    try {
        runSubtitleProbe($reconcileSubtitleCase);
    } catch (ConnectionException) {
        // The queue would retry; this is the final attempt.
    }

    $reconcileSubtitleCase->failed(new ConnectionException('bazarr unreachable'));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($this->case->fresh()->failure_reason)->toContain('probe');
});

test('a definite upstream rejection parks the case without retrying', function (): void {
    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['message' => 'bad request'], 422),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);

    runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case)));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview);
});

test('a probe without Bazarr effective score capability creates no download request', function (): void {
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
            'score' => 99,
        ]]]),
        'bazarr.test/api/providers' => Http::response(['data' => [[
            'name' => 'OpenSubtitles',
            'status' => 'healthy',
            'throttled_until' => null,
        ]]]),
        'bazarr.test/api/system/settings' => Http::response(['data' => []]),
    ]);

    runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case)));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and(ActionRequest::query()->where('type', 'bazarr_download_best')->count())->toBe(0)
        ->and(SubtitleCaseAttempt::query()->firstOrFail()->outcome)->toBe(SubtitleCaseAttemptOutcome::Indeterminate)
        ->and(SubtitleCaseAttempt::query()->firstOrFail()->summary['capability_limited'])->toBe(1);
});

test('a probe uses Bazarr effective score instead of the MediaManager replacement threshold', function (): void {
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
            'score' => 91,
        ]]]),
        'bazarr.test/api/providers' => Http::response(['data' => [[
            'name' => 'OpenSubtitles',
            'status' => 'healthy',
            'throttled_until' => null,
        ]]]),
        'bazarr.test/api/system/settings' => Http::response(['data' => [
            'minimum_score' => 95,
            'minimum_score_movie' => 80,
        ]]),
    ]);

    runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case)));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and(ActionRequest::query()->where('type', 'bazarr_download_best')->count())->toBe(0)
        ->and(SubtitleCaseAttempt::query()->firstOrFail()->summary['below_threshold'])->toBe(1);
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
        'bazarr.test/api/system/settings' => Http::response(['data' => [
            'minimum_score' => 90,
            'minimum_score_movie' => 80,
        ]]),
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

/**
 * Wire the case's Bazarr connection to a mapped Sonarr, point the case at a
 * concrete episode target, and fake the upstream reads the targeted-verification
 * path performs. The installed subtitle languages are supplied by the caller.
 *
 * @param  list<array<string, mixed>>  $subtitleTracks
 * @return array<string, ServiceConnection|Collection<int, ServiceConnection>|SubtitleCase|Collection<int, SubtitleCase>|null>
 */
function targetedEpisodeCaseSetup(array $subtitleTracks): array
{
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'secret',
    ]);
    $sonarr = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.test',
        'api_key' => 'sonarr-secret',
    ]);
    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);

    Http::fake([
        'bazarr.test/api/episodes*' => Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => $subtitleTracks,
        ]]]),
        'sonarr.test/api/v3/series/101' => Http::response(['id' => 101, 'title' => 'Frieren', 'seriesType' => 'anime']),
        'sonarr.test/api/v3/episode?seriesId=101' => Http::response([
            ['id' => 701, 'seriesId' => 101, 'episodeFileId' => 501],
        ]),
        'sonarr.test/api/v3/episodefile/501' => Http::response([
            'id' => 501,
            'size' => 734003200,
            'dateAdded' => '2026-07-16T08:00:00Z',
            'sceneName' => 'Group.Frieren.S01E01',
            'path' => '/private/anime/Frieren S01E01.mkv',
        ]),
        // Empty probe endpoints for the post-transition re-probe path.
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => []]),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);

    $case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'media_type' => 'episode',
        'scope' => 'anime',
        'status' => SubtitleCaseStatus::DownloadRequested,
        'target_ids' => ['series_id' => 101, 'episode_id' => 701, 'episode_file_id' => 501],
        'required_languages' => [['code' => 'eng', 'forced' => false, 'hearing_impaired' => false]],
        'evidence' => ['display_name' => 'Frieren S01E01', 'missing_languages' => ['eng']],
    ]);

    // Align the case's identity fingerprints with the live projection so the
    // reconciler matches this exact case.
    $candidate = resolve(SubtitleInventoryService::class)->caseCandidateFor($case);
    $case->forceFill([
        'file_fingerprint' => $candidate['file_fingerprint'],
        'requirements_fingerprint' => $candidate['requirements_fingerprint'],
    ])->save();

    return ['bazarr' => $bazarr, 'sonarr' => $sonarr, 'case' => $case->fresh()];
}

test('targeted reconcile resolves a download_requested case when the required track appeared', function (): void {
    ['case' => $case] = targetedEpisodeCaseSetup([
        ['code3' => 'eng', 'name' => 'English', 'path' => '/private/anime/Frieren S01E01.en.srt', 'forced' => false, 'hi' => false],
    ]);

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);
});

test('a completed download that did not satisfy the requirement re-enters probing', function (): void {
    ['case' => $case] = targetedEpisodeCaseSetup([]);
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ]);
    $case->forceFill(['download_action_request_id' => $actionRequest->id])->save();

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
});

test('a pending download leaves a still-missing case in download_requested', function (): void {
    ['case' => $case] = targetedEpisodeCaseSetup([]);
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Approved,
    ]);
    $case->forceFill(['download_action_request_id' => $actionRequest->id])->save();

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);
});

test('a probe with multiple missing languages creates one download request per language', function (): void {
    Date::setTestNow('2026-07-17 10:00:00');
    ActionTypeConfig::factory()->create([
        'type' => 'bazarr_download_best',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);
    $case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->sonarr->id,
        'media_type' => 'episode',
        'scope' => 'anime',
        'status' => SubtitleCaseStatus::BazarrSearching,
        'target_ids' => ['series_id' => 101, 'episode_id' => 701, 'episode_file_id' => 501],
        'required_languages' => [
            ['code' => 'eng', 'forced' => false, 'hearing_impaired' => false],
            ['code' => 'swe', 'forced' => false, 'hearing_impaired' => false],
        ],
        'evidence' => ['display_name' => 'Frieren — Part One', 'missing_languages' => ['eng', 'swe']],
    ]);

    $multiLanguageCandidate = static fn (SubtitleCase $subtitleCase): array => [
        'bazarr_connection_id' => $subtitleCase->bazarr_connection_id,
        'service_connection_id' => $subtitleCase->service_connection_id,
        'service' => 'sonarr',
        'media_type' => $subtitleCase->media_type,
        'scope' => $subtitleCase->scope,
        'target_ids' => $subtitleCase->target_ids,
        'display_name' => $subtitleCase->evidence['display_name'],
        'required_languages' => ['eng', 'swe'],
        'missing_languages' => ['eng', 'swe'],
        'current_subtitles' => [],
        'monitored' => true,
        'file_fingerprint' => $subtitleCase->file_fingerprint,
        'requirements_fingerprint' => $subtitleCase->requirements_fingerprint,
    ];

    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => [
            ['provider' => 'OpenSubtitles', 'subtitle' => 'eng-id', 'language' => 'eng', 'forced' => false, 'hearing_impaired' => false, 'score' => 95],
            ['provider' => 'OpenSubtitles', 'subtitle' => 'swe-id', 'language' => 'swe', 'forced' => false, 'hearing_impaired' => false, 'score' => 95],
        ]]),
        'bazarr.test/api/providers' => Http::response(['data' => [
            ['name' => 'OpenSubtitles', 'status' => 'healthy', 'throttled_until' => null],
        ]]),
        'bazarr.test/api/system/settings' => Http::response(['data' => ['minimum_score' => 90, 'minimum_score_movie' => 80]]),
    ]);

    runSubtitleProbe(new ReconcileSubtitleCase($multiLanguageCandidate($case)));

    $requests = ActionRequest::query()->where('type', 'bazarr_download_best')->get();
    expect($requests)->toHaveCount(2)
        ->and($requests->map(fn (ActionRequest $actionRequest): mixed => $actionRequest->payload['language'])->sort()->values()->all())
        ->toBe(['eng', 'swe']);

    // Re-probing the same case after the spacing window must reuse the per-language
    // linked requests rather than creating duplicates.
    $case->fresh()->forceFill(['status' => SubtitleCaseStatus::BazarrSearching])->save();
    Date::setTestNow('2026-07-18 12:00:00');
    runSubtitleProbe(new ReconcileSubtitleCase($multiLanguageCandidate($case->fresh())));

    expect(ActionRequest::query()->where('type', 'bazarr_download_best')->count())->toBe(2);
});

test('advisor escalation reserves one cycle slot per case so repeats do not starve other cases', function (): void {
    Date::setTestNow('2026-07-17 10:00:00');
    config(['mediamanager.ai.enabled' => true]);
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
        'max_advisor_escalations_per_cycle' => 2,
    ]);

    $this->case->forceFill(['status' => SubtitleCaseStatus::ReplacementEligible])->save();
    $secondCase = $this->case->replicate()->fill([
        'file_fingerprint' => hash('sha256', 'second-file'),
        'requirements_fingerprint' => hash('sha256', 'second-requirements'),
        'status' => SubtitleCaseStatus::ReplacementEligible,
    ]);
    $secondCase->save();

    Queue::fake([RunSubtitleAdvisor::class]);

    foreach ([1, 2, 3] as $ignored) {
        runSubtitleProbe(ReconcileSubtitleCase::forCase($this->case->fresh()));
    }

    runSubtitleProbe(ReconcileSubtitleCase::forCase($secondCase->fresh()));

    Queue::assertPushed(RunSubtitleAdvisor::class, 2);
    Queue::assertPushed(RunSubtitleAdvisor::class, fn (RunSubtitleAdvisor $job): bool => $job->subtitleCaseId === $this->case->id);
    Queue::assertPushed(RunSubtitleAdvisor::class, fn (RunSubtitleAdvisor $job): bool => $job->subtitleCaseId === $secondCase->id);
    expect((int) Cache::get('bazarr-advisor-cycle-count:'.$this->bazarr->id))->toBe(2);
});

test('probe searches honour a shared per-cycle probe budget regardless of origin', function (): void {
    Date::setTestNow('2026-07-17 10:00:00');
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
        'max_probes_per_cycle' => 1,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => []]),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);

    $secondCase = $this->case->replicate()->fill([
        'file_fingerprint' => hash('sha256', 'budget-second-file'),
        'requirements_fingerprint' => hash('sha256', 'budget-second-requirements'),
    ]);
    $secondCase->save();

    runSubtitleProbe(ReconcileSubtitleCase::forCase($this->case->fresh()));
    runSubtitleProbe(ReconcileSubtitleCase::forCase($secondCase->fresh()));

    expect(SubtitleCaseAttempt::query()->where('type', SubtitleCaseAttemptType::Probe)->count())->toBe(1);
    Http::assertSentCount(2); // one provider search (episodes + providers) for the first case only
});

test('the probe rate limiter applies only when probing is allowed', function (): void {
    $allowed = new ReconcileSubtitleCase([], probeAllowed: true);
    $suppressed = new ReconcileSubtitleCase([], probeAllowed: false);

    expect($allowed->middleware())->toHaveCount(1)
        ->and($allowed->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($suppressed->middleware())->toBe([]);
});

function runSubtitleProbe(ReconcileSubtitleCase $reconcileSubtitleCase): void
{
    $reconcileSubtitleCase->handle(
        resolve(SubtitleCaseReconciler::class),
        resolve(SubtitleCandidateEligibility::class),
        resolve(BazarrDownloadRequestCreator::class),
        resolve(SubtitleCaseLifecycle::class),
        resolve(BazarrAutomationSettings::class),
        resolve(BazarrSettingsAdapter::class),
        resolve(SubtitleInventoryService::class),
    );
}
