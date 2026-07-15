<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\MediaReplacementTracker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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
    $attempt = trackerAttempt($this->connection->id, [
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

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::Downloading);
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

    resolve(MediaReplacementTracker::class)->recordGrab($this->connection, grabPayload());

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($b->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($mediaReplacementAttempt->fresh()->failure_reason)->toBe('ambiguous_webhook_correlation');
    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 2);
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

    resolve(MediaReplacementTracker::class)->verifyDownload($this->connection, [
        'eventType' => 'Download',
        'series' => ['id' => 42],
        'downloadId' => 'DL-1',
    ]);

    $fresh = $mediaReplacementAttempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::Verified)
        ->and($fresh->verification['missing'])->toBe([]);
    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);

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
    $attempt = trackerAttempt($this->connection->id, [
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

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::Verified);
});

test('a download completes an attempt that was awaiting manual interaction', function (): void {
    // An operator resolved a manual import; ARR then emits the Download event.
    // The attempt (needs_attention / manual_interaction_required) must still be
    // verifiable and remonitorable, not permanently excluded.
    $attempt = trackerAttempt($this->connection->id, [
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

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::Verified);
});

test('manual interaction on a tracked download needs attention', function (): void {
    $mediaReplacementAttempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'status' => MediaReplacementStatus::Downloading,
    ]);

    resolve(MediaReplacementTracker::class)->recordManualIntervention($this->connection, [
        'eventType' => 'ManualInteractionRequired',
        'series' => ['id' => 42],
        'downloadInfo' => ['downloadId' => 'DL-1'],
    ]);

    expect($mediaReplacementAttempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
});

test('an ambiguous mid-cleanup inspection with empty required languages is not finalized verified', function (): void {
    // Empty effective languages are supported, so `missing === []` alone is not a
    // success signal: an ambiguous / no-file inspection must still fail. The pending
    // verification captures the exact predicate so the executor's finalizer respects
    // it rather than reconstructing success from an empty `missing`.
    $attempt = trackerAttempt($this->connection->id, [
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
    $pending = $attempt->fresh();
    expect($pending->status)->toBe(MediaReplacementStatus::Downloading)
        ->and($pending->verification['subtitles_ok'])->toBeFalse();

    // Executor finishes cleanup (monitoring restored); the finalizer must NOT
    // report the ambiguous inspection as Verified despite an empty `missing`.
    $pending->forceFill(['monitoring_suspended' => false, 'cleanup_completed_at' => now()])->save();
    resolve(MediaReplacementTracker::class)->finalizeAfterCleanup($this->connection, $pending);

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($attempt->fresh()->failure_reason)->toBe('imported_subtitles_missing_required_language');
});

test('a Download whose inspection outlives the executor cleanup still finalizes (no lost wakeup)', function (): void {
    // Mid-cleanup snapshot: monitoring suspended, cleanup not yet complete.
    $attempt = trackerAttempt($this->connection->id, [
        'download_id' => 'DL-1',
        'status' => MediaReplacementStatus::Downloading,
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'cleanup_completed_at' => null,
        'required_languages' => ['eng'],
    ]);

    $tracker = resolve(MediaReplacementTracker::class);

    // While the tracker is still inspecting (the slow episodefile GET), the executor
    // finishes its cleanup: it restores monitoring, sets cleanup_completed_at, and
    // calls finalizeAfterCleanup() — a no-op here because the pending verification is
    // not written yet. verifyDownload() then resumes with its STALE phase snapshot
    // (cleanupDone=false) and takes the pending branch. Without the re-read handoff
    // nobody would finalize the consumed Download.
    Http::fake(function (Request $request) use ($attempt, $tracker): mixed {
        if (str_contains($request->url(), '/api/v3/episodefile/501') && $attempt->fresh()->cleanup_completed_at === null) {
            $attempt->forceFill(['monitoring_suspended' => false, 'cleanup_completed_at' => now()])->save();
            $tracker->finalizeAfterCleanup($this->connection, $attempt->fresh());
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

    $tracker->verifyDownload($this->connection, [
        'eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'DL-1',
    ]);

    // The webhook re-read the phase after storing verification, saw cleanup done,
    // and finalized it itself rather than leaving it stuck downloading.
    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::Verified)
        ->and($attempt->fresh()->verification['subtitles_ok'])->toBeTrue();
});

test('the cleanup finalizer does not clobber a terminal state another webhook set', function (): void {
    // A Download webhook verified subtitles mid-cleanup and left the attempt
    // pending. Before the executor finalizes, a ManualInteractionRequired webhook
    // terminalizes it (operator action required). The finalizer must preserve that
    // terminal result rather than reporting Verified and hiding the action.
    $attempt = trackerAttempt($this->connection->id, [
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

    resolve(MediaReplacementTracker::class)->finalizeAfterCleanup($this->connection, $attempt);

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->failure_reason)->toBe('manual_interaction_required');
    // Only the manual-intervention notification fired; the finalizer added none.
    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
});
