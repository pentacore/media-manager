<?php

declare(strict_types=1);

use App\Cache\Services\BazarrCache;
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
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

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

    // The job retries with a 60/300 second backoff until its retryUntil window
    // closes, so a brief Bazarr or provider outage must surface as a failure the
    // queue can retry rather than permanently parking the case.
    expect(function () use ($reconcileSubtitleCase): void {
        runSubtitleProbe($reconcileSubtitleCase);
    })->toThrow(ConnectionException::class);

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and(SubtitleCaseAttempt::query()->where('type', SubtitleCaseAttemptType::Probe)->firstOrFail()->outcome)
        ->toBe(SubtitleCaseAttemptOutcome::Failed);
});

test('a queue retry after a transient probe failure searches Bazarr again', function (): void {
    $searches = 0;
    Http::fake([
        'bazarr.test/api/providers/episodes*' => function () use (&$searches): never {
            $searches++;

            throw new ConnectionException('bazarr unreachable');
        },
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);
    $reconcileSubtitleCase = new ReconcileSubtitleCase(probeCandidate($this->case));

    // The recorded failure must not satisfy the probe-spacing guard, or the
    // queued retry returns without contacting Bazarr: the remaining tries are
    // never spent, failed() never runs, and the case waits out the whole
    // spacing window in bazarr_searching.
    foreach (range(1, 2) as $ignored) {
        try {
            runSubtitleProbe($reconcileSubtitleCase);
        } catch (ConnectionException) {
            // The queue would retry after the configured backoff.
        }
    }

    // The client retries a connection failure internally, so the second run is
    // only proven by searches beyond the first run's exhausted attempts.
    expect($searches)->toBeGreaterThan(3)
        ->and(SubtitleCaseAttempt::query()->where('outcome', SubtitleCaseAttemptOutcome::Failed)->count())->toBe(2)
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
});

test('a retry does not need a second cycle slot to reach Bazarr', function (): void {
    // One probe per cycle: the first attempt spends the budget, so a retry that
    // asked for another slot would be refused, return normally, and let the queue
    // delete the job with tries left and failed() never running.
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
        'max_probes_per_cycle' => 1,
    ]);
    $searches = 0;
    Http::fake([
        'bazarr.test/api/providers/episodes*' => function () use (&$searches): never {
            $searches++;

            throw new ConnectionException('bazarr unreachable');
        },
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);
    $reconcileSubtitleCase = new ReconcileSubtitleCase(probeCandidate($this->case));

    foreach (range(1, 2) as $ignored) {
        try {
            runSubtitleProbe($reconcileSubtitleCase);
        } catch (ConnectionException) {
            // The queue would retry after the configured backoff.
        }
    }

    expect($searches)->toBeGreaterThan(3)
        ->and(SubtitleCaseAttempt::query()->where('outcome', SubtitleCaseAttemptOutcome::Failed)->count())->toBe(2);
});

test('an independently dispatched job cannot reuse another job&apos;s cycle reservation', function (): void {
    // One probe per cycle, already spent by the first job's failed attempt. A second
    // job — the sweep, a webhook, an action listener — must not read that failure as
    // its own retry and search anyway.
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
        'max_probes_per_cycle' => 1,
    ]);
    $searches = 0;
    Http::fake([
        'bazarr.test/api/providers/episodes*' => function () use (&$searches): never {
            $searches++;

            throw new ConnectionException('bazarr unreachable');
        },
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);

    try {
        runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case)));
    } catch (ConnectionException) {
        // The first job keeps its reservation for its own retries.
    }

    $searchesAfterFirstJob = $searches;

    runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case)));

    expect($searches)->toBe($searchesAfterFirstJob)
        ->and(SubtitleCaseAttempt::query()->where('outcome', SubtitleCaseAttemptOutcome::Failed)->count())->toBe(1);
});

test('a job payload queued before the reservation identity existed still probes', function (): void {
    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => []]),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);

    // Exactly how PHP restores a payload written by the previous release: the four
    // properties it knew about are set and the constructor never runs, so the typed
    // reservationId stays uninitialized.
    $reconcileSubtitleCase = new ReflectionClass(ReconcileSubtitleCase::class)->newInstanceWithoutConstructor();
    $reconcileSubtitleCase->candidate = probeCandidate($this->case);
    $reconcileSubtitleCase->probeAllowed = true;
    $reconcileSubtitleCase->subtitleCaseId = null;
    $reconcileSubtitleCase->targetBazarrConnectionId = null;

    runSubtitleProbe($reconcileSubtitleCase);

    // The probe ran instead of dying on an uninitialized property, and the derived
    // identity is stable, so this message's own retries share one reservation.
    $secondRestore = new ReflectionClass(ReconcileSubtitleCase::class)->newInstanceWithoutConstructor();
    $secondRestore->candidate = probeCandidate($this->case);
    $secondRestore->probeAllowed = true;
    $secondRestore->subtitleCaseId = null;
    $secondRestore->targetBazarrConnectionId = null;

    expect(SubtitleCaseAttempt::query()->where('type', SubtitleCaseAttemptType::Probe)->count())->toBe(1)
        ->and($reconcileSubtitleCase->reservationId)->toStartWith('payload:')
        ->and(Cache::get('bazarr-probe-cycle-reservation:'.$this->bazarr->id.':'.$reconcileSubtitleCase->reservationId))->not->toBeNull();

    // Probe again from a second restore of the same payload — the spacing record and
    // the cycle counter are cleared so the run reaches the reservation.
    SubtitleCaseAttempt::query()->delete();
    Cache::forget('bazarr-probe-cycle-count:'.$this->bazarr->id);
    runSubtitleProbe($secondRestore);

    expect($secondRestore->reservationId)->toBe($reconcileSubtitleCase->reservationId);
});

test('the last probe attempt parks the case for review', function (): void {
    Http::fake([
        'bazarr.test/api/providers/episodes*' => fn (): never => throw new ConnectionException('bazarr unreachable'),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);
    // The queue payload is serialized at dispatch, and both the attempt and the
    // failure handler are reconstructed from it — so nothing the throwing instance
    // recorded about the case survives into failed().
    $payload = serialize(new ReconcileSubtitleCase(probeCandidate($this->case)));

    try {
        runSubtitleProbe(unserialize($payload));
    } catch (ConnectionException) {
        // The queue would retry; this is the final attempt.
    }

    unserialize($payload)->failed(new ConnectionException('bazarr unreachable'));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($this->case->fresh()->failure_reason)->toContain('probe');
});

test('failure handling leaves a case that already moved on alone', function (): void {
    $payload = serialize(new ReconcileSubtitleCase(probeCandidate($this->case)));
    resolve(SubtitleCaseLifecycle::class)->resolve($this->case);

    unserialize($payload)->failed(new ConnectionException('bazarr unreachable'));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);
});

test('a stale targeted job does not park a case that has queued a download', function (): void {
    $payload = serialize(ReconcileSubtitleCase::forCase($this->case));

    // While this job sat in backoff, another dispatch queued a download for the same
    // case. Parking it now would strand that download: the completion listener only
    // verifies a case that is still download_requested.
    resolve(SubtitleCaseLifecycle::class)->transition($this->case, SubtitleCaseStatus::DownloadRequested);

    unserialize($payload)->failed(new ConnectionException('bazarr unreachable'));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);
});

test('a targeted job still parks its case while it is searching', function (): void {
    $payload = serialize(ReconcileSubtitleCase::forCase($this->case));

    unserialize($payload)->failed(new ConnectionException('bazarr unreachable'));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview);
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
 * @param  (callable(): mixed)|null  $episodesHandler  Replaces the Bazarr episodes
 *                                                     read, so a test can make it
 *                                                     fail; Http::fake() appends
 *                                                     stubs and the first match
 *                                                     wins, so it cannot be
 *                                                     overridden afterwards.
 * @return array<string, ServiceConnection|Collection<int, ServiceConnection>|SubtitleCase|Collection<int, SubtitleCase>|null>
 */
function targetedEpisodeCaseSetup(array $subtitleTracks, ?callable $episodesHandler = null): array
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
        'bazarr.test/api/episodes*' => $episodesHandler ?? Http::response(['data' => [[
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

test('a failed verification read keeps the case waiting and retries', function (): void {
    // The verification read fails, then later shows the required track. Treating the
    // failure as "still missing" would move the case out of download_requested — the
    // only state this verification inspects — while a satisfied target has also left
    // the bulk missing feed, so nothing would look again.
    $tracks = [];
    $failReads = false;
    ['case' => $case, 'bazarr' => $bazarr] = targetedEpisodeCaseSetup([], function () use (&$tracks, &$failReads): mixed {
        throw_if($failReads, ConnectionException::class, 'bazarr unreachable');

        return Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => $tracks,
        ]]]);
    });
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ]);
    $case->forceFill(['download_action_request_id' => $actionRequest->id])->save();

    $failReads = true;
    new BazarrCache($bazarr)->bustAll();

    expect(fn (): mixed => runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh())))
        ->toThrow(ConnectionException::class)
        ->and($case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);

    // The queued retry reads the target successfully and finds the subtitle in place.
    $failReads = false;
    $tracks = [[
        'code3' => 'eng',
        'name' => 'English',
        'path' => '/private/anime/Frieren S01E01.en.srt',
        'forced' => false,
        'hi' => false,
    ]];
    new BazarrCache($bazarr)->bustAll();

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);
});

test('a targeted verification that exhausts its retries parks the waiting case', function (): void {
    $failReads = false;
    ['case' => $case, 'bazarr' => $bazarr] = targetedEpisodeCaseSetup([], function () use (&$failReads): mixed {
        throw_if($failReads, ConnectionException::class, 'bazarr unreachable');

        return Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => [],
        ]]]);
    });
    $case->forceFill(['download_action_request_id' => ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ])->id])->save();
    $failReads = true;
    new BazarrCache($bazarr)->bustAll();
    $payload = serialize(ReconcileSubtitleCase::forCase($case->fresh()));

    // Every attempt rethrows, so the retryUntil window eventually closes and the
    // queue calls failed(). This job is the only verifier of a download_requested
    // case — it is outside the bulk missing feed and its completion listener has
    // already fired — so it must not be dropped still waiting.
    expect(fn (): mixed => runSubtitleProbe(unserialize($payload)))
        ->toThrow(ConnectionException::class)
        ->and($case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);

    unserialize($payload)->failed(new ConnectionException('bazarr unreachable'));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($case->fresh()->failure_reason)->toBe('bazarr_verification_failed');
});

test("another dispatch's verification outage is not this job's claim", function (): void {
    ['case' => $case] = targetedEpisodeCaseSetup([]);
    $case->forceFill(['download_action_request_id' => ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ])->id])->save();

    // A different verifier recorded a transient read failure for this case and then
    // recovered; the case is waiting on a live download again.
    SubtitleCaseAttempt::factory()->for($case)->create([
        'type' => SubtitleCaseAttemptType::Reconciliation,
        'outcome' => SubtitleCaseAttemptOutcome::Failed,
        'error_category' => 'verification_read_failure',
        'summary' => ['result' => 'verification_read_failed', 'reservation' => 'another-dispatch'],
        'started_at' => now()->subMinutes(5),
        'completed_at' => now()->subMinutes(5),
    ]);

    // A stale probe payload must not read that marker as its own ownership.
    unserialize(serialize(ReconcileSubtitleCase::forCase($case->fresh())))
        ->failed(new ConnectionException('bazarr unreachable'));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);
});

test('a read that failed while searching claims no ownership of a later download', function (): void {
    $failReads = false;
    ['case' => $case, 'bazarr' => $bazarr] = targetedEpisodeCaseSetup([], function () use (&$failReads): mixed {
        throw_if($failReads, ConnectionException::class, 'bazarr unreachable');

        return Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => [],
        ]]]);
    });
    resolve(SubtitleCaseLifecycle::class)->transition($case->fresh(), SubtitleCaseStatus::BazarrSearching);
    $failReads = true;
    new BazarrCache($bazarr)->bustAll();
    $payload = serialize(ReconcileSubtitleCase::forCase($case->fresh()));

    // This job's read failed while the case was still searching, so it never verified
    // a download.
    expect(fn (): mixed => runSubtitleProbe(unserialize($payload)))
        ->toThrow(ConnectionException::class);

    // Another dispatch then queued a download for the same case.
    resolve(SubtitleCaseLifecycle::class)->transition(
        $case->fresh(),
        SubtitleCaseStatus::DownloadRequested,
    );

    unserialize($payload)->failed(new ConnectionException('bazarr unreachable'));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);
});

test('an expired targeted job leaves a case that already resolved alone', function (): void {
    ['case' => $case] = targetedEpisodeCaseSetup([]);
    $case->forceFill(['download_action_request_id' => ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ])->id])->save();
    $payload = serialize(ReconcileSubtitleCase::forCase($case->fresh()));

    // Another dispatch verified the case in the meantime.
    resolve(SubtitleCaseLifecycle::class)->resolve($case->fresh());

    unserialize($payload)->failed(new ConnectionException('bazarr unreachable'));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);
});

test('a definitive verification failure parks the case for an operator', function (): void {
    $failReads = false;
    ['case' => $case, 'bazarr' => $bazarr] = targetedEpisodeCaseSetup([], function () use (&$failReads): mixed {
        if ($failReads) {
            return Http::response(['message' => 'bad request'], 422);
        }

        return Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => [],
        ]]]);
    });
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ]);
    $case->forceFill(['download_action_request_id' => $actionRequest->id])->save();

    $failReads = true;
    new BazarrCache($bazarr)->bustAll();

    // A 422 fails the same way on every attempt, and this job is the last thing
    // looking: a waiting case is not in the bulk candidate feed and the completion
    // listener has already fired. Leaving it download_requested strands it silently.
    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($case->fresh()->failure_reason)->toBe('bazarr_verification_failed');
});

test('an unreadable target parks the case for an operator', function (): void {
    $vanished = false;
    ['case' => $case, 'bazarr' => $bazarr] = targetedEpisodeCaseSetup([], function () use (&$vanished): mixed {
        if ($vanished) {
            return Http::response(['data' => []]);
        }

        return Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => [],
        ]]]);
    });
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ]);
    $case->forceFill(['download_action_request_id' => $actionRequest->id])->save();

    // The episode is gone from Bazarr, so the projection returns null — authoritative
    // about nothing, and nothing else will read this case again.
    $vanished = true;
    new BazarrCache($bazarr)->bustAll();

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($case->fresh()->failure_reason)->toBe('bazarr_target_unreadable');
});

test('an unverifiable case is left alone while its Bazarr connection is inactive', function (): void {
    $vanished = false;
    ['case' => $case, 'bazarr' => $bazarr] = targetedEpisodeCaseSetup([], function () use (&$vanished): mixed {
        if ($vanished) {
            return Http::response(['data' => []]);
        }

        return Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => [],
        ]]]);
    });
    $case->forceFill(['download_action_request_id' => ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ])->id])->save();

    // Deactivating a connection pauses automation; it must not bury every waiting
    // case of that connection in review noise.
    $vanished = true;
    $bazarr->forceFill(['is_active' => false])->save();
    new BazarrCache($bazarr)->bustAll();

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);
});

test('an indeterminate download re-enters probing once the live read finds the track still missing', function (): void {
    ['case' => $case] = targetedEpisodeCaseSetup([]);
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Failed,
        'result' => ['success' => false, 'reason' => 'needs_reconciliation', 'indeterminate' => true],
    ]);
    $case->forceFill(['download_action_request_id' => $actionRequest->id])->save();

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
});

test('a settled download re-enters probing even inside the probe spacing window', function (): void {
    ['case' => $case] = targetedEpisodeCaseSetup([]);
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ]);
    $case->forceFill(['download_action_request_id' => $actionRequest->id])->save();

    // The probe that queued the download is minutes old and the spacing window is
    // 24 hours, so gating this verification on spacing would strand the case in
    // download_requested forever. probe() below still honours the window.
    SubtitleCaseAttempt::factory()->for($case)->create([
        'type' => SubtitleCaseAttemptType::Probe,
        'outcome' => SubtitleCaseAttemptOutcome::Succeeded,
        'started_at' => now()->subMinutes(2),
        'completed_at' => now()->subMinutes(2),
    ]);

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching)
        ->and(SubtitleCaseAttempt::query()->where('subtitle_case_id', $case->id)->count())->toBe(1);
});

test('a case keeps waiting until every per-language download has settled', function (): void {
    ['case' => $case] = targetedEpisodeCaseSetup([]);
    $swedish = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Approved,
    ]);
    $english = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'status' => ActionRequestStatus::Completed,
    ]);
    // English was queued last, so the scalar column points at it while Swedish is
    // still in flight. Only the evidence map records both.
    $case->forceFill([
        'download_action_request_id' => $english->id,
        'evidence' => [
            ...$case->evidence,
            'download_requests' => ['swe|0|0' => $swedish->id, 'eng|0|0' => $english->id],
        ],
    ])->save();

    runSubtitleProbe(ReconcileSubtitleCase::forCase($case->fresh()));

    // Leaving download_requested now would strand the case: Swedish completing later
    // finds no waiting case to reconcile, and a satisfied target also drops out of
    // the bulk missing feed.
    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);

    $swedish->forceFill(['status' => ActionRequestStatus::Completed])->save();

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

test('the provider search is throttled in the job rather than in the queue', function (): void {
    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => []]),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);
    // No queue middleware: a release counts as an attempt even though handle() never
    // ran, so a cycle larger than the per-minute budget used to burn every job's
    // attempts and deadline before reaching Bazarr.
    expect(new ReconcileSubtitleCase([], probeAllowed: true)->middleware())->toBe([])
        ->and(new ReconcileSubtitleCase([], probeAllowed: false)->middleware())->toBe([]);

    // The throttle now guards the search itself: with the minute's budget spent, the
    // job completes without contacting the providers instead of being released.
    RateLimiter::clear('bazarr-probe-searches:'.$this->bazarr->id);

    foreach (range(1, 10) as $ignored) {
        RateLimiter::hit('bazarr-probe-searches:'.$this->bazarr->id);
    }

    runSubtitleProbe(new ReconcileSubtitleCase(probeCandidate($this->case)));

    Http::assertNothingSent();
    expect(SubtitleCaseAttempt::query()->count())->toBe(0)
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
});

test('a cycle far larger than the throttle budget still probes what it admits', function (): void {
    // max_cases_per_cycle accepts 1000. Queue-level limiting made the tail of such a
    // cycle expire without a single provider call; in-job throttling admits the
    // budget and leaves the rest for the next cycle, with no attempts consumed.
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
        'max_cases_per_cycle' => 1000,
        'max_probes_per_cycle' => 100,
    ]);
    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => []]),
        'bazarr.test/api/providers' => Http::response(['data' => []]),
    ]);
    RateLimiter::clear('bazarr-probe-searches:'.$this->bazarr->id);
    $cases = Collection::make(range(1, 12))->map(fn (int $index): SubtitleCase => SubtitleCase::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->sonarr->id,
        'status' => SubtitleCaseStatus::BazarrSearching,
        'target_ids' => ['series_id' => 101, 'episode_id' => 700 + $index, 'episode_file_id' => 500 + $index],
        'required_languages' => [['code' => 'eng', 'forced' => false, 'hearing_impaired' => false]],
        'evidence' => ['display_name' => 'Episode '.$index, 'missing_languages' => ['eng']],
    ]));

    foreach ($cases as $case) {
        runSubtitleProbe(ReconcileSubtitleCase::forCase($case));
    }

    // Exactly the minute's budget searched; the rest are untouched and still
    // searching, so the next cycle picks them up.
    expect(SubtitleCaseAttempt::query()->where('type', SubtitleCaseAttemptType::Probe)->count())->toBe(10)
        ->and($cases->last()->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
});

test("admission never consumes a probe job's attempts or deadline", function (): void {
    Date::setTestNow('2026-07-17 10:00:00');
    $reconcileSubtitleCase = new ReconcileSubtitleCase(probeCandidate($this->case));
    $triesAttributes = new ReflectionClass(ReconcileSubtitleCase::class)->getAttributes(Tries::class);

    // Nothing releases this job for admission any more, so no attempt or deadline is
    // spent waiting: the deadline only has to outlast the 60/300-second backoff of a
    // genuine upstream outage, whatever max_cases_per_cycle is set to.
    expect($triesAttributes)->toBe([])
        ->and($reconcileSubtitleCase->tries ?? null)->toBeNull()
        ->and($reconcileSubtitleCase->middleware())->toBe([])
        ->and($reconcileSubtitleCase->retryUntil())->toBeGreaterThan(
            now()->addSeconds(array_sum($reconcileSubtitleCase->backoff()) * 2),
        );
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
