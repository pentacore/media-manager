<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Enums\MediaReplacementStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
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
    private const array TERMINAL_STATUSES = [
        MediaReplacementStatus::Verified,
        MediaReplacementStatus::Failed,
        MediaReplacementStatus::NeedsAttention,
    ];

    public function __construct(
        private MediaFileInspector $mediaFileInspector,
        private LanguageNormalizer $languageNormalizer,
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

            $matches = $this->nonTerminalAttempts($serviceConnection)->filter(
                fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => $this->attemptTargetId($mediaReplacementAttempt) === $targetId
                    && $this->normalizeTitle((string) ($mediaReplacementAttempt->candidate['title'] ?? '')) === $this->normalizeTitle($title),
            );

            if ($matches->count() === 1) {
                $matches->first()->update(['download_id' => $this->downloadId($payload)]);

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

            // Restore the ORIGINAL monitoring the executor suspended — but ONLY
            // once the executor has finished its cleanup phase
            // (cleanup_completed_at set) and monitoring is in fact still
            // suspended. Deferring until cleanup is complete is what prevents this
            // remonitor from racing the executor's blocklist (the executor owns
            // the restore during its own run). If the executor is still cleaning
            // up, leave monitoring alone; it (or a later event) will restore it.
            $needsRestore = $attempt->monitoring_suspended === true;
            $cleanupDone = $attempt->cleanup_completed_at !== null;
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

            $attempt->update([
                'status' => $ok ? MediaReplacementStatus::Verified : MediaReplacementStatus::NeedsAttention,
                'verification' => $verification,
                'completed_at' => now(),
                'failure_reason' => $ok ? null : implode(',', $reasons),
            ]);

            $this->notify(
                $serviceConnection,
                $attempt,
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
            $mediaReplacementAttempt->update([
                'status' => MediaReplacementStatus::NeedsAttention,
                'failure_reason' => 'manual_interaction_required',
                'completed_at' => now(),
            ]);

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
            ->whereNotIn('status', array_map(static fn (MediaReplacementStatus $mediaReplacementStatus): string => $mediaReplacementStatus->value, self::TERMINAL_STATUSES))
            ->get();
    }

    /**
     * @return Collection<int, MediaReplacementAttempt>
     */
    private function attemptsByDownloadId(ServiceConnection $serviceConnection, string $downloadId): Collection
    {
        // Non-terminal attempts, plus recoverable needs_attention attempts: one
        // the reconciliation sweep timed out (download_timeout) or one that was
        // waiting on a manual import (manual_interaction_required). In both cases
        // a late Download event means the file finally imported, so it must still
        // be verifiable/remonitorable rather than permanently excluded.
        return MediaReplacementAttempt::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->where('download_id', $downloadId)
            ->where(function ($query): void {
                $query->whereNotIn('status', array_map(
                    static fn (MediaReplacementStatus $status): string => $status->value,
                    self::TERMINAL_STATUSES,
                ))->orWhere(function ($recoverable): void {
                    $recoverable->where('status', MediaReplacementStatus::NeedsAttention->value)
                        ->whereIn('failure_reason', ['download_timeout', 'manual_interaction_required']);
                });
            })
            ->get();
    }

    /**
     * @param  Collection<int, MediaReplacementAttempt>  $attempts
     */
    private function flagAmbiguous(Collection $attempts, ServiceConnection $serviceConnection): void
    {
        foreach ($attempts as $attempt) {
            $attempt->update([
                'status' => MediaReplacementStatus::NeedsAttention,
                'failure_reason' => 'ambiguous_webhook_correlation',
                'completed_at' => now(),
            ]);

            $this->notify(
                $serviceConnection,
                $attempt,
                'warning',
                'Replacement webhook correlation was ambiguous and needs manual review.',
            );
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
     * @param  array<string, mixed>  $payload
     */
    private function downloadId(array $payload): ?string
    {
        $downloadId = $payload['downloadId'] ?? ($payload['downloadInfo']['downloadId'] ?? null);

        return is_string($downloadId) && trim($downloadId) !== '' ? $downloadId : null;
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

    private function attemptTargetId(MediaReplacementAttempt $mediaReplacementAttempt): ?int
    {
        $target = is_array($mediaReplacementAttempt->target) ? $mediaReplacementAttempt->target : [];
        $id = ($target['service'] ?? null) === 'radarr'
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
            if (mb_strtolower(trim((string) ($target['service'] ?? ''))) === 'radarr') {
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
