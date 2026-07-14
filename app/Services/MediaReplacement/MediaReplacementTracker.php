<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
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
                fn (MediaReplacementAttempt $attempt): bool => $this->attemptTargetId($attempt) === $targetId
                    && $this->normalizeTitle((string) ($attempt->candidate['title'] ?? '')) === $this->normalizeTitle($title),
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
            );

            $required = $this->normalizeCodes($attempt->required_languages);
            $found = ($snapshot['ambiguous'] ?? false) === true
                ? []
                : $this->normalizeCodes($snapshot['subtitles'] ?? []);
            $missing = array_values(array_diff($required, $found));

            $verification = ['required' => $required, 'found' => $found, 'missing' => $missing];
            $verified = ($snapshot['ambiguous'] ?? false) !== true && $missing === [];

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

            $attempt = $matches->first();
            $attempt->update([
                'status' => MediaReplacementStatus::NeedsAttention,
                'failure_reason' => 'manual_interaction_required',
                'completed_at' => now(),
            ]);

            $this->notify(
                $serviceConnection,
                $attempt,
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
            ->whereNotIn('status', array_map(static fn (MediaReplacementStatus $status): string => $status->value, self::TERMINAL_STATUSES))
            ->get();
    }

    /**
     * @return Collection<int, MediaReplacementAttempt>
     */
    private function attemptsByDownloadId(ServiceConnection $serviceConnection, string $downloadId): Collection
    {
        return $this->nonTerminalAttempts($serviceConnection)->filter(
            static fn (MediaReplacementAttempt $attempt): bool => $attempt->download_id === $downloadId,
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
        MediaReplacementAttempt $attempt,
        string $level,
        string $message,
    ): void {
        $admins = User::query()->where('role', UserRole::Admin)->get();

        if ($admins->isEmpty()) {
            return;
        }

        $title = (string) ($attempt->candidate['title'] ?? 'Media replacement');

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

    private function attemptTargetId(MediaReplacementAttempt $attempt): ?int
    {
        $target = is_array($attempt->target) ? $attempt->target : [];
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
     * @param  mixed  $languages
     * @return list<string>
     */
    private function normalizeCodes(mixed $languages): array
    {
        if (! is_array($languages)) {
            return [];
        }

        return $this->languageNormalizer->normalizeMany(
            array_values(array_filter($languages, 'is_string')),
        );
    }

    private function guarded(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            Log::error('Media replacement webhook tracking failed.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }
}
