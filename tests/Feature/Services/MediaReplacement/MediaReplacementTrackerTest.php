<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\MediaReplacementTracker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    Notification::fake();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    User::factory()->create(['role' => UserRole::Admin]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function trackerAttempt(int $connectionId, array $overrides = []): MediaReplacementAttempt
{
    return MediaReplacementAttempt::factory()->create(array_replace([
        'service_connection_id' => $connectionId,
        'status' => MediaReplacementStatus::Requested,
        'scope' => 'anime',
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'season_number' => 1, 'episode_numbers' => [1], 'episode_ids' => [101],
            'episode_file_ids' => [501], 'installed_release' => 'OLD',
        ],
        'candidate' => ['title' => 'Trusted.Anime.S01E01.CR', 'fingerprint' => 'fp'],
        'required_languages' => ['eng'],
        'download_id' => null,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function grabPayload(array $overrides = []): array
{
    return array_replace([
        'eventType' => 'Grab',
        'series' => ['id' => 42, 'title' => 'Trusted Anime'],
        'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 1]],
        'release' => ['releaseTitle' => 'Trusted.Anime.S01E01.CR'],
        'downloadId' => 'DL-1',
    ], $overrides);
}

test('a tracking failure degrades gracefully instead of tearing down webhook processing', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'download_id' => 'DL-2',
    ]);

    // Re-inspection fails at the arr API mid-verification.
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([], 500),
        'sonarr.local:8989/api/v3/episode*' => Http::response([], 500),
        'sonarr.local:8989/api/v3/history*' => Http::response([], 500),
    ]);

    // Must not throw — the webhook handler's other side effects must still run.
    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download',
        'series' => ['id' => 42],
        'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 1]],
        'downloadId' => 'DL-2',
    ]);

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::Downloading);
});

function fakeInspectSubtitles(string $subtitles): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'Trusted.Anime.S01E01.CR', 'mediaInfo' => ['subtitles' => $subtitles],
        ]),
        'sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 200),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);
}

test('a unique grab attaches the download id to the requested attempt', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id);

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload());

    expect($mediaReplacementAttempt->fresh()->download_id)->toBe('DL-1')
        ->and($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::Requested);
    Notification::assertNothingSent();
});

test('multiple matching attempts on grab are flagged for attention rather than guessed', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id);
    $b = trackerAttempt($this->connection->id);
    Event::fake([MediaReplacementAttemptChanged::class]);

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload());

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($b->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($mediaReplacementAttempt->fresh()->failure_reason)->toBe('ambiguous_webhook_correlation');
    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 2);
    Event::assertDispatched(MediaReplacementAttemptChanged::class, 2);
});

test('a download verifies the attempt when every required language is present', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'required_languages' => ['eng', 'jpn'],
        'was_monitored' => true,
        // Executor finished its cleanup and left monitoring suspended (the
        // indeterminate/late-import path), so the tracker owns restoration.
        'monitoring_suspended' => true,
        'cleanup_completed_at' => now(),
    ]);
    fakeInspectSubtitles('English / Japanese');
    Event::fake([MediaReplacementAttemptChanged::class]);

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download',
        'series' => ['id' => 42],
        'downloadId' => 'DL-1',
    ]);

    $fresh = $mediaReplacementAttempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::Verified)
        ->and($fresh->verification['missing'])->toBe([]);
    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
    Event::assertDispatchedOnce(MediaReplacementAttemptChanged::class);

    // Monitoring suspended by the executor is restored to the ORIGINAL state.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['episodeIds'] === [101]
        && $request->data()['monitored'] === true);
});

test('does not restore monitoring for an originally-unmonitored target', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'was_monitored' => false,
    ]);
    fakeInspectSubtitles('English');
    Event::fake([MediaReplacementAttemptChanged::class]);

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'DL-1',
    ]);

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::Verified);
    // Never (re)monitor media that was unmonitored to begin with.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/episode/monitor'));
});

test('a verified import whose monitoring cannot be restored needs attention rather than reporting success', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'cleanup_completed_at' => now(),
    ]);
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'A', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501, 'monitored' => false],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response(['id' => 501, 'sceneName' => 'x', 'mediaInfo' => ['subtitles' => 'English']]),
        'sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 500), // restore fails
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'DL-1',
    ]);

    $fresh = $mediaReplacementAttempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->failure_reason)->toBe('restore_monitoring_failed');
});

test('a download missing a required language needs attention with verification evidence', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'required_languages' => ['eng', 'swe'],
    ]);
    fakeInspectSubtitles('English');
    Event::fake([MediaReplacementAttemptChanged::class]);

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download',
        'series' => ['id' => 42],
        'downloadId' => 'DL-1',
    ]);

    $fresh = $mediaReplacementAttempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->verification['required'])->toBe(['eng', 'swe'])
        ->and($fresh->verification['found'])->toBe(['eng'])
        ->and($fresh->verification['missing'])->toBe(['swe']);
    Event::assertDispatchedOnce(MediaReplacementAttemptChanged::class);
});

test('a download with an unknown download id is a no-op', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, ['download_id' => 'DL-1']);

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'OTHER',
    ]);

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::Requested);
    Notification::assertNothingSent();
});

test('a late download still verifies an attempt the reconcile sweep timed out', function (): void {
    // The reconciliation sweep terminalized a stuck download as
    // needs_attention/download_timeout; a later Download webhook must still be
    // able to verify and restore it rather than being permanently excluded.
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::NeedsAttention,
        'failure_reason' => 'download_timeout',
        'download_id' => 'DL-1',
        'was_monitored' => false,
        'required_languages' => ['eng'],
    ]);
    fakeInspectSubtitles('English');

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download',
        'series' => ['id' => 42],
        'downloadId' => 'DL-1',
    ]);

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::Verified);
});

test('a download completes an attempt that was awaiting manual interaction', function (): void {
    // An operator resolved a manual import; ARR then emits the Download event.
    // The attempt (needs_attention / manual_interaction_required) must still be
    // verifiable and remonitorable, not permanently excluded.
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::NeedsAttention,
        'failure_reason' => 'manual_interaction_required',
        'download_id' => 'DL-1',
        'was_monitored' => false,
        'required_languages' => ['eng'],
    ]);
    fakeInspectSubtitles('English');

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download',
        'series' => ['id' => 42],
        'downloadId' => 'DL-1',
    ]);

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::Verified);
});

test('manual interaction on a tracked download needs attention', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'status' => MediaReplacementStatus::Downloading,
    ]);
    Event::fake([MediaReplacementAttemptChanged::class]);

    $payload = [
        'eventType' => 'ManualInteractionRequired',
        'series' => ['id' => 42],
        'downloadInfo' => ['downloadId' => 'DL-1'],
    ];
    resolve(MediaReplacementTracker::class)->recordManualIntervention($this->connection, $payload);
    resolve(MediaReplacementTracker::class)->recordManualIntervention($this->connection, $payload);

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
    Event::assertDispatchedOnce(MediaReplacementAttemptChanged::class);
});

test('an ambiguous mid-cleanup inspection with empty required languages is not finalized verified', function (): void {
    // Empty effective languages are supported, so `missing === []` alone is not a
    // success signal: an ambiguous / no-file inspection must still fail. The pending
    // verification captures the exact predicate so the executor's finalizer respects
    // it rather than reconstructing success from an empty `missing`.
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'required_languages' => [],
        'status' => MediaReplacementStatus::Downloading,
        'was_monitored' => true,
        // Mid-cleanup: monitoring suspended, cleanup not yet complete → pending.
        'monitoring_suspended' => true,
        'cleanup_completed_at' => null,
    ]);

    // Episode resolves but has no file id → inspection is ambiguous ('no_file').
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1],
        ]),
        'sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 200),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'DL-1',
    ]);

    // Left pending (non-terminal) with the exact predicate stored as false.
    $pending = $mediaReplacementAttempt->fresh();
    expect($pending->status)->toBe(MediaReplacementStatus::Downloading)
        ->and($pending->verification['subtitles_ok'])->toBeFalse();

    // Executor finishes cleanup (monitoring restored); the finalizer must NOT
    // report the ambiguous inspection as Verified despite an empty `missing`.
    $pending->forceFill(['monitoring_suspended' => false, 'cleanup_completed_at' => now()])->save();
    Event::fake([MediaReplacementAttemptChanged::class]);
    resolve(MediaReplacementTracker::class)->finalizeAfterCleanup($this->connection, $pending);

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($mediaReplacementAttempt->fresh()->failure_reason)->toBe('imported_subtitles_missing_required_language');
    Event::assertDispatchedOnce(MediaReplacementAttemptChanged::class);
});

test('a Download whose inspection outlives the executor cleanup still finalizes (no lost wakeup)', function (): void {
    // Mid-cleanup snapshot: monitoring suspended, cleanup not yet complete.
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'status' => MediaReplacementStatus::Downloading,
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'cleanup_completed_at' => null,
        'required_languages' => ['eng'],
    ]);

    $mediaReplacementTracker = resolve(MediaReplacementTracker::class);

    // While the tracker is still inspecting (the slow episodefile GET), the executor
    // finishes its cleanup: it restores monitoring, sets cleanup_completed_at, and
    // calls finalizeAfterCleanup() — a no-op here because the pending verification is
    // not written yet. verifyDownload() then resumes with its STALE phase snapshot
    // (cleanupDone=false) and takes the pending branch. Without the re-read handoff
    // nobody would finalize the consumed Download.
    Http::fake(function (Request $request) use ($mediaReplacementAttempt, $mediaReplacementTracker): mixed {
        if (str_contains($request->url(), '/api/v3/episodefile/501') && $mediaReplacementAttempt->fresh()->cleanup_completed_at === null) {
            $mediaReplacementAttempt->forceFill(['monitoring_suspended' => false, 'cleanup_completed_at' => now()])->save();
            $mediaReplacementTracker->finalizeAfterCleanup($this->connection, $mediaReplacementAttempt->fresh());
        }

        return match (true) {
            str_contains($request->url(), '/api/v3/series/42') => Http::response(['id' => 42, 'title' => 'A', 'seriesType' => 'anime']),
            str_contains($request->url(), '/api/v3/episode?seriesId=42') => Http::response([
                ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
            ]),
            str_contains($request->url(), '/api/v3/episodefile/501') => Http::response([
                'id' => 501, 'sceneName' => 'x', 'mediaInfo' => ['subtitles' => 'English'],
            ]),
            str_contains($request->url(), '/api/v3/history') => Http::response(['records' => []]),
            default => Http::response([], 200),
        };
    });

    $mediaReplacementTracker->verifyDownload($this->connection, [
        'eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'DL-1',
    ]);

    // The webhook re-read the phase after storing verification, saw cleanup done,
    // and finalized it itself rather than leaving it stuck downloading.
    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::Verified)
        ->and($mediaReplacementAttempt->fresh()->verification['subtitles_ok'])->toBeTrue();
});

test('the cleanup finalizer does not clobber a terminal state another webhook set', function (): void {
    // A Download webhook verified subtitles mid-cleanup and left the attempt
    // pending. Before the executor finalizes, a ManualInteractionRequired webhook
    // terminalizes it (operator action required). The finalizer must preserve that
    // terminal result rather than reporting Verified and hiding the action.
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'status' => MediaReplacementStatus::Downloading,
        'monitoring_suspended' => false,
        'cleanup_completed_at' => now(),
        'verification' => ['required' => ['eng'], 'found' => ['eng'], 'missing' => [], 'subtitles_ok' => true],
    ]);

    resolve(MediaReplacementTracker::class)->recordManualIntervention($this->connection, [
        'eventType' => 'ManualInteractionRequired',
        'series' => ['id' => 42],
        'downloadInfo' => ['downloadId' => 'DL-1'],
    ]);

    resolve(MediaReplacementTracker::class)->finalizeAfterCleanup($this->connection, $mediaReplacementAttempt);

    $fresh = $mediaReplacementAttempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->failure_reason)->toBe('manual_interaction_required');
    // Only the manual-intervention notification fired; the finalizer added none.
    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
});

test('finalizeAfterCleanup restores the monitoring the executor deliberately left suspended', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'download_id' => 'DL-3',
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'cleanup_completed_at' => now(),
        'required_languages' => ['eng'],
        'verification' => ['required' => ['eng'], 'found' => ['eng'], 'missing' => [], 'subtitles_ok' => true],
    ]);

    Http::fake(['sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 202)]);

    resolve(MediaReplacementTracker::class)->finalizeAfterCleanup($this->connection, $mediaReplacementAttempt);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['monitored'] === true);

    $mediaReplacementAttempt->refresh();

    expect($mediaReplacementAttempt->monitoring_suspended)->toBeFalse()
        ->and($mediaReplacementAttempt->status)->toBe(MediaReplacementStatus::Verified);
});

test('finalizeAfterCleanup reports needs_attention when the restore fails', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'download_id' => 'DL-4',
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'cleanup_completed_at' => now(),
        'required_languages' => ['eng'],
        'verification' => ['required' => ['eng'], 'found' => ['eng'], 'missing' => [], 'subtitles_ok' => true],
    ]);

    Http::fake(['sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 500)]);

    resolve(MediaReplacementTracker::class)->finalizeAfterCleanup($this->connection, $mediaReplacementAttempt);

    $mediaReplacementAttempt->refresh();

    expect($mediaReplacementAttempt->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($mediaReplacementAttempt->failure_reason)->toBe('restore_monitoring_failed');
});

/**
 * A queue holding one competing row on our target. Every competing-grab test
 * below fakes it — including the negatives: CompetingGrabSweeper::sweep()
 * swallows Throwable, so an unfaked negative would turn a wrongly-run sweep
 * into a silent stray-request no-op and assert nothing.
 */
function fakeTrackerCompetingQueue(): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
            ['id' => 920, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-RACE', 'title' => 'Competing.Release'],
        ]]),
        'sonarr.local:8989/api/v3/queue/*' => Http::response([], 200),
    ]);
}

test('a grab for our target with a different release is swept as a competing grab', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => now(),
        'download_id' => 'DL-OURS',
    ]);

    fakeTrackerCompetingQueue();

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload([
        'release' => ['releaseTitle' => 'Competing.Release'],
        'downloadId' => 'DL-RACE',
    ]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/920')
        && str_contains($request->url(), 'skipRedownload=true'));

    // The attempt itself is untouched: its own download is still in flight.
    $mediaReplacementAttempt->refresh();

    expect($mediaReplacementAttempt->status)->toBe(MediaReplacementStatus::Downloading)
        ->and($mediaReplacementAttempt->download_id)->toBe('DL-OURS');
});

test('a matching grab still correlates and records the download id', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
    ]);

    fakeTrackerCompetingQueue();

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload());

    $mediaReplacementAttempt->refresh();

    expect($mediaReplacementAttempt->download_id)->toBe('DL-1');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a grab for an unrelated target is ignored without sweeping', function (): void {
    // Armed (download_id + grab_accepted_at) and a competing row is in the
    // queue, so only the target filter stands between this grab and a DELETE.
    trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => now(),
        'download_id' => 'DL-OURS',
    ]);

    fakeTrackerCompetingQueue();

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload([
        'series' => ['id' => 99, 'title' => 'Unrelated'],
        'release' => ['releaseTitle' => 'Unrelated.S01E01'],
    ]));

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a grab before our own is accepted is not treated as competing', function (): void {
    // grab_accepted_at null means we have not yet confirmed our own grab, so a
    // title mismatch here could be our own release under a different name.
    // download_id IS set: the Grab webhook records it, and it can land before
    // the executor writes grab_accepted_at — the exact window this gate covers,
    // and the one in which the sweeper would otherwise already be armed.
    trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => null,
        'download_id' => 'DL-OURS',
    ]);

    fakeTrackerCompetingQueue();

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload([
        'release' => ['releaseTitle' => 'Something.Else'],
    ]));

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a grab with no download id still sweeps a competing release off our target', function (): void {
    // downloadId is absent, so there is nothing to correlate or record — but the
    // release title still says this grab is not ours, and the attempt's own
    // recorded download id is what arms the sweep, not the payload's.
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => now(),
        'download_id' => 'DL-OURS',
    ]);

    fakeTrackerCompetingQueue();

    $payload = grabPayload(['release' => ['releaseTitle' => 'Competing.Release']]);
    unset($payload['downloadId']);

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, $payload);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/920'));

    expect($mediaReplacementAttempt->fresh()->download_id)->toBe('DL-OURS');
});

test('a grab elsewhere in the same series sweeps but removes nothing off our episode', function (): void {
    // targetId() is series-level, so a grab anywhere in series 42 correlates to
    // our attempt and fires the sweep. That is deliberate — the sweep, not the
    // correlation, is what decides which rows belong to our target — so this
    // pins both halves: the sweep really runs, and it spares the other episode.
    trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => now(),
        'download_id' => 'DL-OURS',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
            ['id' => 950, 'seriesId' => 42, 'episodeId' => 999, 'downloadId' => 'DL-EP9', 'title' => 'Trusted.Anime.S01E09.OTHER'],
        ]]),
        'sonarr.local:8989/api/v3/queue/*' => Http::response([], 200),
    ]);

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload([
        'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 9]],
        'release' => ['releaseTitle' => 'Trusted.Anime.S01E09.OTHER'],
        'downloadId' => 'DL-EP9',
    ]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v3/queue?'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a matching grab without a download id does not clear the id already recorded', function (): void {
    // The stored download id is the only thing that arms the competing-grab
    // sweep. A redelivered/malformed Grab webhook must not disarm every later
    // pass by blanking it.
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'download_id' => 'DL-OURS',
    ]);

    $payload = grabPayload();
    unset($payload['downloadId']);

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, $payload);

    expect($mediaReplacementAttempt->fresh()->download_id)->toBe('DL-OURS');
});

test('a grab with no release title is a no-op rather than a sweep', function (): void {
    // Without a title there is no evidence the grab is not ours, and the sweep
    // removes what it cannot identify — so an untitled payload must do nothing.
    trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => now(),
        'download_id' => 'DL-OURS',
    ]);

    fakeTrackerCompetingQueue();

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload([
        'release' => [],
        'downloadId' => 'DL-RACE',
    ]));

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a stored service in mixed case still correlates a radarr grab', function (): void {
    // attemptTargetId() must normalize `service` the way remonitorTarget() and
    // the rest of the namespace do. Compared exactly, 'Radarr' falls through to
    // series_id — which a movie target does not have — so the attempt gets a null
    // target id, drops out of correlation altogether, and never records the
    // download id that later events and the competing-grab sweep depend on.
    $radarrConnection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
    ]);

    $mediaReplacementAttempt = MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $radarrConnection->id,
        'status' => MediaReplacementStatus::Downloading,
        'scope' => 'movie',
        'target' => ['service' => 'Radarr', 'scope' => 'movie', 'movie_id' => 7, 'movie_file_ids' => [70]],
        'candidate' => ['title' => 'Movie.2020.GOOD', 'fingerprint' => 'fp'],
        'required_languages' => ['eng'],
        'download_id' => null,
    ]);

    resolve(MediaReplacementTracker::class)->recordGrab($radarrConnection, [
        'eventType' => 'Grab',
        'movie' => ['id' => 7, 'title' => 'Movie'],
        'release' => ['releaseTitle' => 'Movie.2020.GOOD'],
        'downloadId' => 'DL-M1',
    ]);

    expect($mediaReplacementAttempt->fresh()->download_id)->toBe('DL-M1');
});

test('a padded webhook download id is stored trimmed so it can be compared', function (): void {
    // The stored id is what the competing-grab sweep is armed with and what it
    // compares against the queue's own ids. Storing " DL-1 " would arm a sweep
    // that can never recognise the replacement's own download.
    $mediaReplacementAttempt = trackerAttempt($this->connection->id);

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload([
        'downloadId' => "  DL-1\t",
    ]));

    expect($mediaReplacementAttempt->fresh()->download_id)->toBe('DL-1');
});

test('an import belonging to a tracked attempt is recognised', function (): void {
    trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'download_id' => 'DL-TRACKED',
    ]);

    expect(resolve(MediaReplacementTracker::class)->hasAttemptForDownload($this->connection, 'DL-TRACKED'))
        ->toBeTrue();
});

test('an unrelated import is not recognised', function (): void {
    // Two ways this could wrongly claim an organic import, so both are present:
    // an attempt on THIS connection carrying a different download id, and an
    // attempt carrying THIS download id on a different connection. Arr download
    // ids are only unique within their own arr, so a lookup that ignores the
    // connection would let one instance's replacement silence another's import.
    trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'download_id' => 'DL-TRACKED',
    ]);

    $otherConnection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr-two.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    trackerAttempt($otherConnection->id, [
        'status' => MediaReplacementStatus::Downloading,
        'download_id' => 'DL-ORGANIC',
    ]);

    expect(resolve(MediaReplacementTracker::class)->hasAttemptForDownload($this->connection, 'DL-ORGANIC'))
        ->toBeFalse();
});

test('a terminalized attempt still claims its own import', function (): void {
    // The Download webhook that terminalizes an attempt and the auditor both
    // see the same event; the auditor must not act on it in either ordering.
    // Were terminal attempts excluded, whichever ordering ran the auditor after
    // verifyDownload() would have it audit the file the replacement just
    // imported and request yet another replacement — an intermittent loop.
    trackerAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Verified,
        'completed_at' => now(),
        'download_id' => 'DL-DONE',
    ]);

    expect(resolve(MediaReplacementTracker::class)->hasAttemptForDownload($this->connection, 'DL-DONE'))
        ->toBeTrue();
});
