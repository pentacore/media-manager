<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Enums\MediaReplacementStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Correlates Sonarr/Radarr Grab/Download/ManualInteractionRequired webhooks with
 * durable MediaReplacementAttempt records and performs deterministic, AI-free
 * post-import subtitle verification. Correlation must be unique; ambiguous
 * correlation flags the matches needs_attention instead of guessing.
 */
final readonly class MediaReplacementTracker
{
    /**
     * needs_attention reasons that a later webhook may still resolve: the
     * sweep timed the download out, a manual import was pending, or the old
     * file's deletion failed after an accepted grab. In each case a late
     * Download event means the file actually imported, so the attempt must
     * stay verifiable/remonitorable rather than permanently excluded.
     */
    private const array RECOVERABLE_FAILURE_REASONS = [
        'download_timeout',
        'manual_interaction_required',
        'deletion_failed',
    ];

    public function __construct(
        private MediaFileInspector $mediaFileInspector,
        private LanguageNormalizer $languageNormalizer,
        private CompetingGrabSweeper $competingGrabSweeper,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordGrab(ServiceConnection $serviceConnection, array $payload): void
    {
        $this->guarded(function () use ($serviceConnection, $payload): void {
            $title = $this->candidateTitle($payload);
            $targetId = $this->targetId($serviceConnection, $payload);

            if ($title === null || $targetId === null) {
                return;
            }

            $onTarget = $this->nonTerminalAttempts($serviceConnection)->filter(
                fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => $this->attemptTargetId($mediaReplacementAttempt) === $targetId,
            );

            $matches = $onTarget->filter(
                fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => $this->normalizeTitle((string) ($mediaReplacementAttempt->candidate['title'] ?? '')) === $this->normalizeTitle($title),
            );

            if ($matches->count() === 1) {
                $downloadId = $this->downloadId($payload);
                $mediaReplacementAttempt = $matches->first();

                if (! $mediaReplacementAttempt instanceof MediaReplacementAttempt || $downloadId === null) {
                    return;
                }

                $storedDownloadId = is_string($mediaReplacementAttempt->download_id)
                    && trim($mediaReplacementAttempt->download_id) !== ''
                        ? trim($mediaReplacementAttempt->download_id)
                        : null;

                if ($storedDownloadId === null) {
                    $mediaReplacementAttempt->update(['download_id' => $downloadId]);

                    return;
                }

                // The first non-empty id is durable identity. A redelivery of
                // the same Grab is idempotent; a later same-title Grab carrying
                // another id is a competitor and must never steal correlation
                // from the vetted download.
                if ($storedDownloadId !== $downloadId) {
                    $this->sweepCompetingGrab($serviceConnection, $matches);
                }

                return;
            }

            if ($matches->isEmpty()) {
                $this->sweepCompetingGrab($serviceConnection, $onTarget);

                return;
            }

            $this->flagAmbiguous($matches, $serviceConnection);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyDownload(ServiceConnection $serviceConnection, array $payload): void
    {
        $this->guarded(function () use ($serviceConnection, $payload): void {
            $downloadId = $this->downloadId($payload);

            if ($downloadId === null) {
                return;
            }

            $matches = $this->attemptsByDownloadId($serviceConnection, $downloadId);

            if ($matches->count() > 1) {
                $this->flagAmbiguous($matches, $serviceConnection);

                return;
            }

            $attempt = $matches->first();

            if (! $attempt instanceof MediaReplacementAttempt) {
                return;
            }

            // Bust the connection's ARR cache BEFORE re-inspecting: the webhook
            // handler only busts it after this runs, so a cached
            // getSeries/getEpisodes/getMovie would otherwise return the
            // pre-replacement file id/subtitles and falsely verify (or 404 on)
            // the old file during a fast webhook-during-delete interleave.
            $this->bustConnectionCache($serviceConnection);

            $snapshot = $this->mediaFileInspector->inspectFromSnapshot(
                is_array($attempt->target) ? $attempt->target : [],
                $serviceConnection,
            );

            $required = $this->normalizeCodes($attempt->required_languages);
            $found = ($snapshot['ambiguous'] ?? false) === true
                ? []
                : $this->normalizeCodes($snapshot['subtitles'] ?? []);
            $missing = array_values(array_diff($required, $found));

            $verification = ['required' => $required, 'found' => $found, 'missing' => $missing];
            $subtitlesOk = ($snapshot['ambiguous'] ?? false) !== true && $missing === [];

            $needsRestore = $attempt->monitoring_suspended === true;
            $cleanupDone = $attempt->cleanup_completed_at !== null;

            // Import arrived while the executor is still mid-cleanup: monitoring was
            // intentionally suspended and restoring it now could precede the
            // blocklist and the re-search the blocklist queues. Record the subtitle
            // verification but leave the attempt PENDING — terminalizing it here as
            // restore_monitoring_failed would be false (no restore was attempted).
            // finalizeAfterCleanup() restores monitoring and terminalizes this
            // stored verification once the cleanup phase closes.
            if ($needsRestore && ! $cleanupDone) {
                // Persist the EXACT success predicate (which also rejects an
                // ambiguous / no-file inspection), not just an empty `missing`:
                // with empty required languages `missing === []` alone would later
                // finalize an ambiguous inspection as Verified, whereas the direct
                // path correctly rejects it.
                $attempt->update(['verification' => [...$verification, 'subtitles_ok' => $subtitlesOk]]);

                // Close the lost-wakeup window in the handoff: $cleanupDone was read
                // BEFORE the slow inspection above. If the executor completed cleanup
                // meanwhile, its finalizeAfterCleanup() already ran against a still
                // null verification and no-oped — so no actor would finalize this
                // now-stored verification, leaving the attempt stuck downloading. Re
                // read the phase and finalize here when cleanup is already done;
                // otherwise the executor's own post-cleanup call completes it. Either
                // ordering resolves, and the conditional terminal update makes a
                // double finalize idempotent.
                $attempt->refresh();

                if ($attempt->cleanup_completed_at !== null) {
                    $this->finalizeAfterCleanup($serviceConnection, $attempt);
                }

                return;
            }

            // Restore the ORIGINAL monitoring the executor suspended — but ONLY
            // once the cleanup phase has closed (cleanup_completed_at set) and
            // monitoring is in fact still suspended. Deferring until then is what
            // keeps the restore behind the blocklist and behind the re-search the
            // blocklist queues inside the arr. This is the normal path: an import
            // arrives minutes after cleanup, by which time that search has run and
            // been rejected against the unmonitored target.
            $restored = ! $needsRestore
                || ($cleanupDone && $this->remonitorTarget($serviceConnection, is_array($attempt->target) ? $attempt->target : []));

            if ($restored && $needsRestore && $cleanupDone) {
                $attempt->forceFill(['monitoring_suspended' => false])->save();
            }

            // A clean success needs BOTH the required subtitles present AND (if
            // the executor suspended it) monitoring restored. Either failing —
            // independently — must be recorded in the terminal state and the
            // notification so neither is silently dropped.
            $ok = $subtitlesOk && $restored;
            $reasons = array_values(array_filter([
                $subtitlesOk ? null : 'imported_subtitles_missing_required_language',
                $restored ? null : 'restore_monitoring_failed',
            ]));

            $won = $this->terminalize(
                $attempt,
                $ok ? MediaReplacementStatus::Verified : MediaReplacementStatus::NeedsAttention,
                $ok ? null : implode(',', $reasons),
                ['verification' => json_encode($verification)],
            );

            if (! $won) {
                return;
            }

            $this->notify(
                $serviceConnection,
                $attempt,
                $ok ? 'info' : 'warning',
                $this->verificationMessage($subtitlesOk, $restored, $missing),
            );
        });
    }

    /**
     * Finalize a verification a Download webhook recorded while the executor was
     * still mid-cleanup. In that window a restore could have preceded the
     * blocklist, so verifyDownload() left the attempt PENDING (non-terminal) with
     * only its subtitle verification stored, rather than falsely failing it. The
     * executor calls this once it has closed the cleanup phase; this is then the
     * first safe moment to restore monitoring, which it does before terminalizing
     * the stored verification against the resulting monitoring state. No-op unless
     * the attempt is still pending with a recorded verification, so it never
     * clobbers a real webhook terminal outcome nor acts before an import event.
     */
    public function finalizeAfterCleanup(ServiceConnection $serviceConnection, MediaReplacementAttempt $mediaReplacementAttempt): void
    {
        $this->guarded(function () use ($serviceConnection, $mediaReplacementAttempt): void {
            $mediaReplacementAttempt->refresh();

            // Invariant: only finalize once cleanup is actually complete. This makes
            // the handshake safe from either side — a call that races ahead of the
            // executor setting cleanup_completed_at (e.g. the webhook's own re-read)
            // no-ops rather than finalizing against an unfinished restore.
            if ($mediaReplacementAttempt->cleanup_completed_at === null) {
                return;
            }

            if ($mediaReplacementAttempt->status->isTerminal()) {
                return;
            }

            $verification = is_array($mediaReplacementAttempt->verification) ? $mediaReplacementAttempt->verification : null;

            if ($verification === null) {
                return;
            }

            $missing = array_values(array_filter(
                is_array($verification['missing'] ?? null) ? $verification['missing'] : [],
                is_string(...),
            ));
            // Use the EXACT predicate the pending verification captured (it also
            // rejects an ambiguous / no-file inspection); reconstructing it from
            // `missing === []` here would wrongly pass an empty-required ambiguous
            // inspection.
            $subtitlesOk = ($verification['subtitles_ok'] ?? null) === true;
            // The executor no longer restores monitoring — it must stay suspended
            // across the whole window in which the arr runs the re-search its
            // blocklist queued. Cleanup is complete by the time we get here, so
            // this is the first safe moment to put monitoring back.
            $restored = $this->restoreSuspendedMonitoring($mediaReplacementAttempt);

            $ok = $subtitlesOk && $restored;
            $reasons = array_values(array_filter([
                $subtitlesOk ? null : 'imported_subtitles_missing_required_language',
                $restored ? null : 'restore_monitoring_failed',
            ]));

            $won = $this->terminalize(
                $mediaReplacementAttempt,
                $ok ? MediaReplacementStatus::Verified : MediaReplacementStatus::NeedsAttention,
                $ok ? null : implode(',', $reasons),
            );

            if (! $won) {
                return;
            }

            $this->notify(
                $serviceConnection,
                $mediaReplacementAttempt,
                $ok ? 'info' : 'warning',
                $this->verificationMessage($subtitlesOk, $restored, $missing),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordManualIntervention(ServiceConnection $serviceConnection, array $payload): void
    {
        $this->guarded(function () use ($serviceConnection, $payload): void {
            $downloadId = $this->downloadId($payload);

            if ($downloadId === null) {
                return;
            }

            $matches = $this->attemptsByDownloadId($serviceConnection, $downloadId);

            if ($matches->isEmpty()) {
                return;
            }

            if ($matches->count() > 1) {
                $this->flagAmbiguous($matches, $serviceConnection);

                return;
            }

            $mediaReplacementAttempt = $matches->first();

            if ($mediaReplacementAttempt->failure_reason === 'manual_interaction_required') {
                return;
            }

            $won = $this->terminalize($mediaReplacementAttempt, MediaReplacementStatus::NeedsAttention, 'manual_interaction_required');

            if (! $won) {
                return;
            }

            $this->notify(
                $serviceConnection,
                $mediaReplacementAttempt,
                'warning',
                'Replacement needs manual import in Sonarr/Radarr.',
            );
        });
    }

    /**
     * @return Collection<int, MediaReplacementAttempt>
     */
    private function nonTerminalAttempts(ServiceConnection $serviceConnection): Collection
    {
        return MediaReplacementAttempt::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->whereNotIn('status', MediaReplacementStatus::terminalValues())
            ->get();
    }

    /**
     * @return Collection<int, MediaReplacementAttempt>
     */
    private function attemptsByDownloadId(ServiceConnection $serviceConnection, string $downloadId): Collection
    {
        // Non-terminal attempts, plus recoverable needs_attention attempts —
        // see RECOVERABLE_FAILURE_REASONS.
        return MediaReplacementAttempt::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->where('download_id', $downloadId)
            ->where(function (Builder $builder): void {
                $builder->whereNotIn('status', MediaReplacementStatus::terminalValues())
                    ->orWhere(function (Builder $builder): void {
                        $builder->where('status', MediaReplacementStatus::NeedsAttention->value)
                            ->whereIn('failure_reason', self::RECOVERABLE_FAILURE_REASONS);
                    });
            })
            ->get();
    }

    /**
     * Conditionally move an attempt to a terminal status. Only the caller
     * whose update wins may notify — concurrent webhook deliveries and the
     * executor's own finalization would otherwise double-write terminal
     * state, double-remonitor, or overwrite an operator-facing reason.
     * Transitions FROM a recoverable needs_attention reason stay allowed so
     * a late import can still resolve a timed-out/manual/deletion-failed
     * attempt.
     *
     * @param  array<string, mixed>  $extra  additional column writes; array
     *                                       values must be pre-encoded (this bypasses Eloquent casts)
     */
    private function terminalize(
        MediaReplacementAttempt $mediaReplacementAttempt,
        MediaReplacementStatus $status,
        ?string $failureReason,
        array $extra = [],
    ): bool {
        $won = MediaReplacementAttempt::query()
            ->whereKey($mediaReplacementAttempt->id)
            ->where(function (Builder $builder): void {
                $builder->whereNotIn('status', MediaReplacementStatus::terminalValues())
                    ->orWhere(function (Builder $builder): void {
                        $builder->where('status', MediaReplacementStatus::NeedsAttention->value)
                            ->whereIn('failure_reason', self::RECOVERABLE_FAILURE_REASONS);
                    });
            })
            ->update([
                'status' => $status->value,
                'completed_at' => now(),
                'failure_reason' => $failureReason,
                ...$extra,
            ]) === 1;

        if ($won) {
            $mediaReplacementAttempt->refresh();
            event(new MediaReplacementAttemptChanged($mediaReplacementAttempt));
        }

        return $won;
    }

    /**
     * @param  Collection<int, MediaReplacementAttempt>  $attempts
     */
    private function flagAmbiguous(Collection $attempts, ServiceConnection $serviceConnection): void
    {
        foreach ($attempts as $attempt) {
            if ($attempt->failure_reason === 'ambiguous_webhook_correlation') {
                continue;
            }

            $won = $this->terminalize($attempt, MediaReplacementStatus::NeedsAttention, 'ambiguous_webhook_correlation');

            if (! $won) {
                continue;
            }

            $this->notify(
                $serviceConnection,
                $attempt,
                'warning',
                'Replacement webhook correlation was ambiguous and needs manual review.',
            );
        }
    }

    /**
     * A grab landed on a target we are actively replacing, for a release that no
     * attempt on that target vetted. The expected source is the arr's own
     * auto-redownload search — blocklisting the old release makes the arr queue
     * one — but the webhook does not say who asked for the grab, so this claims
     * nothing about the requester. What it acts on is narrower and observable: a
     * second download for this target is starting while a vetted replacement is
     * still in flight, and the two would run in parallel.
     *
     * Only attempts whose own grab the arr has already accepted (grab_accepted_at)
     * may sweep. Before that, a title mismatch could still be our own release
     * under a name the indexer reported differently.
     *
     * The sweep is additionally armed only by the attempt's recorded download_id
     * — see CompetingGrabSweeper — so a competitor grabbed before our own Grab
     * webhook landed removes nothing here. SweepCompetingGrabs' delayed passes
     * cover that gap only where the executor armed them, which is where the
     * blocklist succeeded (MediaReplacementActions guards queueFor on it): where
     * it was declined, nothing cleans such a competitor up and the reconciliation
     * command is the only remaining backstop for the attempt itself. sweep()
     * never throws and logs its own failures, so there is no result to inspect.
     *
     * @param  Collection<int, MediaReplacementAttempt>  $onTarget
     */
    private function sweepCompetingGrab(ServiceConnection $serviceConnection, Collection $onTarget): void
    {
        $accepted = $onTarget->filter(
            static fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => $mediaReplacementAttempt->grab_accepted_at !== null,
        );

        foreach ($accepted as $attempt) {
            $this->competingGrabSweeper->sweep($serviceConnection, $attempt);
        }
    }

    private function notify(
        ServiceConnection $serviceConnection,
        MediaReplacementAttempt $mediaReplacementAttempt,
        string $level,
        string $message,
    ): void {
        $admins = User::query()->where('role', UserRole::Admin)->get();

        if ($admins->isEmpty()) {
            return;
        }

        $title = (string) ($mediaReplacementAttempt->candidate['title'] ?? 'Media replacement');

        Notification::send($admins, new MediaReplacementStatusChanged(
            service: $serviceConnection->type->value,
            title: $title,
            message: $message,
            level: $level,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function candidateTitle(array $payload): ?string
    {
        $candidates = [
            $payload['release']['releaseTitle'] ?? null,
            $payload['release']['title'] ?? null,
            $payload['releaseTitle'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The webhook's download id, TRIMMED. This value is both the correlation key
     * for later events and what arms CompetingGrabSweeper, which compares it
     * against the queue's own ids. Deciding emptiness on the trimmed form while
     * storing the padded one would let a padded " DL-X " arm a sweep that can
     * never recognise our own "DL-X" download — and the sweep removes what it
     * does not recognise, with removeFromClient: true.
     *
     * @param  array<string, mixed>  $payload
     */
    private function downloadId(array $payload): ?string
    {
        $downloadId = $payload['downloadId'] ?? ($payload['downloadInfo']['downloadId'] ?? null);

        if (! is_string($downloadId) || trim($downloadId) === '') {
            return null;
        }

        return trim($downloadId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function targetId(ServiceConnection $serviceConnection, array $payload): ?int
    {
        $id = $serviceConnection->type->value === 'radarr'
            ? ($payload['movie']['id'] ?? null)
            : ($payload['series']['id'] ?? null);

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * The stored target's own media id. `service` is normalized the way
     * remonitorTarget() below and the rest of this namespace normalize it: an
     * exact `=== 'radarr'` here read a stored 'Radarr' or ' radarr' as Sonarr and
     * looked up series_id on a movie target, while remonitorTarget() read the
     * same value as Radarr — two answers for one target, and a null id here
     * silently drops the attempt out of every correlation.
     */
    private function attemptTargetId(MediaReplacementAttempt $mediaReplacementAttempt): ?int
    {
        $target = is_array($mediaReplacementAttempt->target) ? $mediaReplacementAttempt->target : [];
        $id = mb_strtolower(trim((string) ($target['service'] ?? ''))) === 'radarr'
            ? ($target['movie_id'] ?? null)
            : ($target['series_id'] ?? null);

        return is_int($id) && $id > 0 ? $id : null;
    }

    private function normalizeTitle(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    /**
     * @return list<string>
     */
    private function normalizeCodes(mixed $languages): array
    {
        if (! is_array($languages)) {
            return [];
        }

        return $this->languageNormalizer->normalizeMany(
            array_values(array_filter($languages, is_string(...))),
        );
    }

    /**
     * Clear the connection's ARR entity cache so post-import re-inspection reads
     * fresh (post-replacement) file metadata rather than the cached pre-grab file.
     */
    private function bustConnectionCache(ServiceConnection $serviceConnection): void
    {
        $serviceConnection->type === ServiceType::Radarr
            ? new RadarrCache($serviceConnection)->bustAll()
            : new SonarrCache($serviceConnection)->bustAll();
    }

    /**
     * @param  list<string>  $missing
     */
    private function verificationMessage(bool $subtitlesOk, bool $restored, array $missing): string
    {
        if ($subtitlesOk && $restored) {
            return 'Replacement verified: all required subtitles are present.';
        }

        $parts = [];

        if (! $subtitlesOk) {
            $parts[] = sprintf('missing subtitles: %s', implode(', ', $missing));
        }

        if (! $restored) {
            $parts[] = 'monitoring could not be restored';
        }

        return sprintf('Replacement imported but needs review (%s).', implode('; ', $parts));
    }

    /**
     * Restore monitoring the executor suspended. Returns true when monitoring is
     * SETTLED — restored now, or there was nothing to restore — which is what lets
     * callers fold it straight into a success predicate.
     *
     * This is the primary restore for the executor/webhook handshake:
     * finalizeAfterCleanup() calls it as the first safe moment to put monitoring
     * back once the cleanup phase closes. The reconciliation command also calls it
     * for attempts that settle without any import event, where no webhook is coming.
     *
     * Both no-op paths matter and are deliberate: an attempt that never had
     * monitoring suspended returns true untouched, and one suspended on a target the
     * user had already unmonitored has the flag cleared WITHOUT an arr call, because
     * re-enabling monitoring nobody asked for would be wrong.
     */
    public function restoreSuspendedMonitoring(MediaReplacementAttempt $mediaReplacementAttempt): bool
    {
        if ($mediaReplacementAttempt->monitoring_suspended !== true) {
            return true;
        }

        // Suspended but the target was never monitored to begin with: clear
        // the flag without touching the arr — re-enabling monitoring the
        // user had off would be wrong.
        if ($mediaReplacementAttempt->was_monitored !== true) {
            $mediaReplacementAttempt->forceFill(['monitoring_suspended' => false])->save();

            return true;
        }

        $serviceConnection = $mediaReplacementAttempt->serviceConnection;

        if (! $serviceConnection instanceof ServiceConnection) {
            return false;
        }

        $restored = $this->remonitorTarget(
            $serviceConnection,
            is_array($mediaReplacementAttempt->target) ? $mediaReplacementAttempt->target : [],
        );

        if ($restored) {
            $mediaReplacementAttempt->forceFill(['monitoring_suspended' => false])->save();
        }

        return $restored;
    }

    /**
     * Whether a replacement attempt on this connection owns the given download.
     *
     * The automatic subtitle check uses this to stay off its own replacements:
     * a replacement's import is one verifyDownload() verifies, or has already
     * judged, and it flags a still-missing language as
     * imported_subtitles_missing_required_language. "Or has already judged"
     * matters: for an attempt terminal on a NON-recoverable reason,
     * attemptsByDownloadId() excludes it and verifyDownload() returns without
     * re-inspecting. No subtitle judgement is made for that import, and stepping
     * aside is still right — the attempt is already operator-facing, and auditing
     * would fire a fresh replacement at a case a human owns.
     * Terminal attempts count too — the webhook that terminalizes an attempt and
     * the auditor observe the same event, and the auditor must not act on it in
     * either ordering. Deliberately broader than attemptsByDownloadId(), which
     * narrows to the attempts that may still be advanced.
     */
    public function hasAttemptForDownload(ServiceConnection $serviceConnection, string $downloadId): bool
    {
        $downloadId = trim($downloadId);

        if ($downloadId === '') {
            return false;
        }

        return MediaReplacementAttempt::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->where('download_id', $downloadId)
            ->exists();
    }

    /**
     * Restore monitoring on the target after the replacement imported. The
     * executor unmonitors it before blocklisting to suppress the arr's
     * auto-redownload search; this puts it back.
     *
     * Returns whether restoration succeeded so the caller can avoid reporting a
     * verified-but-unmonitored target as a clean success.
     *
     * @param  array<string, mixed>  $target
     */
    private function remonitorTarget(ServiceConnection $serviceConnection, array $target): bool
    {
        try {
            // Shape decided from the CONNECTION, not the target's own `service`. These
            // writes go to the connection the webhook arrived on, so deriving the client
            // from a second source lets a target whose stored service contradicts its
            // connection send a movie call to a Sonarr instance. Same single-source rule
            // CompetingGrabSweeper settled on. No reachable input changes behaviour: the
            // inspector stamps the matching literal into every snapshot it writes, and
            // MediaReplacementActions aborts a replacement whose stored and fresh
            // services disagree — but one source cannot disagree with itself.
            if ($serviceConnection->type === ServiceType::Radarr) {
                $movieId = (int) ($target['movie_id'] ?? 0);

                if ($movieId > 0) {
                    new RadarrClient($serviceConnection)->setMovieMonitored($movieId, true);
                }

                return true;
            }

            $episodeIds = array_values(array_map(intval(...), is_array($target['episode_ids'] ?? null) ? $target['episode_ids'] : []));

            if ($episodeIds !== []) {
                new SonarrClient($serviceConnection)->setEpisodesMonitored($episodeIds, true);
            }

            return true;
        } catch (Throwable $throwable) {
            Log::warning('Media replacement could not restore monitoring after import.', [
                'service_connection_id' => $serviceConnection->id,
                'exception' => $throwable::class,
            ]);

            return false;
        }
    }

    /**
     * Tracking is best-effort and must degrade gracefully: it is wired into
     * webhook handlers ahead of pre-existing must-run side effects (Emby library
     * scan, markProcessed, cache busting, intervention badge), so a transient
     * arr-API failure here must NOT tear down the rest of webhook processing.
     * Log and swallow; the reconciliation sweep flags any attempt left stuck in
     * `downloading` as `needs_attention`.
     */
    private function guarded(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            Log::error('Media replacement webhook tracking failed; continuing webhook processing.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
