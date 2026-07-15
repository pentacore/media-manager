<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementStatus;
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
            $verified = ($snapshot['ambiguous'] ?? false) !== true && $missing === [];

            // The replacement imported, so restore the ORIGINAL monitoring state
            // the executor suspended. Only when the target was originally
            // monitored — never start monitoring media that was unmonitored.
            $restored = $attempt->was_monitored === true
                ? $this->remonitorTarget($serviceConnection, is_array($attempt->target) ? $attempt->target : [])
                : true;

            // A verified import whose monitoring could not be restored must not
            // be reported as a clean success — surface it for manual review so
            // the target is not silently left unmonitored.
            if ($verified && ! $restored) {
                $attempt->update([
                    'status' => MediaReplacementStatus::NeedsAttention,
                    'verification' => $verification,
                    'completed_at' => now(),
                    'failure_reason' => 'restore_monitoring_failed',
                ]);

                $this->notify(
                    $serviceConnection,
                    $attempt,
                    'warning',
                    'Replacement verified but monitoring could not be restored; needs manual review.',
                );

                return;
            }

            $attempt->update([
                'status' => $verified ? MediaReplacementStatus::Verified : MediaReplacementStatus::NeedsAttention,
                'verification' => $verification,
                'completed_at' => now(),
                'failure_reason' => $verified ? null : 'imported_subtitles_missing_required_language',
            ]);

            $this->notify(
                $serviceConnection,
                $attempt,
                $verified ? 'info' : 'warning',
                $verified
                    ? 'Replacement verified: all required subtitles are present.'
                    : sprintf('Replacement imported but missing subtitles: %s.', implode(', ', $missing)),
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
        return $this->nonTerminalAttempts($serviceConnection)->filter(
            static fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => $mediaReplacementAttempt->download_id === $downloadId,
        )->values();
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
