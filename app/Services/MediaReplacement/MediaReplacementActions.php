<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Enums\MediaReplacementStatus;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Executes a replace_media_file ActionRequest with grab-before-delete safety:
 * it revalidates the installed files and the selected release, grabs the
 * replacement, and only then deletes the reviewed file(s) and blocklists the
 * original release. A durable MediaReplacementAttempt tracks the lifecycle.
 */
final readonly class MediaReplacementActions implements ActionExecutor
{
    public function __construct(
        private MediaFileInspector $mediaFileInspector,
        private ReplacementCandidateFinder $replacementCandidateFinder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        throw_if(
            $actionRequest->type !== 'replace_media_file',
            new InvalidArgumentException(sprintf('MediaReplacementActions cannot execute type "%s"', $actionRequest->type)),
        );

        $payload = $actionRequest->payload;
        $service = mb_strtolower(trim((string) ($payload['service'] ?? $actionRequest->target_service)));
        $serviceType = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => throw new InvalidArgumentException(sprintf('Unsupported service "%s"', $service)),
        };

        $storedTarget = is_array($payload['target'] ?? null) ? $payload['target'] : [];
        $fingerprint = (string) ($payload['candidate_fingerprint'] ?? '');
        $requiredLanguages = is_array($payload['required_languages'] ?? null)
            ? array_values(array_filter($payload['required_languages'], 'is_string'))
            : null;

        $freshTarget = $this->mediaFileInspector->inspectFromSnapshot($storedTarget);
        throw_unless(
            $this->sameFiles($storedTarget, $freshTarget),
            new InvalidArgumentException('Installed media files changed after approval; aborting replacement.'),
        );

        $eligible = $this->replacementCandidateFinder->find($freshTarget, $requiredLanguages, 10);
        $stillEligible = array_filter(
            $eligible['candidates'],
            static fn (array $candidate): bool => ($candidate['fingerprint'] ?? null) === $fingerprint,
        );
        throw_if($stillEligible === [], new InvalidArgumentException('Selected release is no longer eligible.'));

        $rawRelease = $this->replacementCandidateFinder->freshRawRelease($freshTarget, $fingerprint);
        throw_if($rawRelease === null, new InvalidArgumentException('Selected release is no longer available.'));

        $serviceConnection = ServiceConnection::resolveActive($serviceType);
        $client = $serviceType === ServiceType::Sonarr
            ? new SonarrClient($serviceConnection)
            : new RadarrClient($serviceConnection);

        $attempt = MediaReplacementAttempt::create([
            'action_request_id' => $actionRequest->id,
            'service_connection_id' => $serviceConnection->id,
            'status' => MediaReplacementStatus::Requested,
            'scope' => (string) ($payload['scope'] ?? ($freshTarget['scope'] ?? 'movie')),
            'target' => $freshTarget,
            'candidate_fingerprint' => $fingerprint,
            'candidate' => is_array($payload['candidate'] ?? null) ? $payload['candidate'] : [],
            'required_languages' => $requiredLanguages ?? $eligible['effective_languages'],
        ]);

        $deletedFiles = $this->grabThenDelete($client, $serviceType, $freshTarget, $rawRelease, $attempt);

        $blocklistWarning = $this->blocklistOriginal($client, $payload['original_history_id'] ?? null, $actionRequest);

        $attempt->update([
            'status' => MediaReplacementStatus::Downloading,
            'started_at' => now(),
        ]);

        $serviceType === ServiceType::Sonarr
            ? new SonarrCache($serviceConnection)->bustAll()
            : new RadarrCache($serviceConnection)->bustAll();

        return [
            'attempt_id' => $attempt->id,
            'status' => MediaReplacementStatus::Downloading->value,
            'replacement_initiated' => true,
            'deleted_files' => $deletedFiles,
            'blocklist_warning' => $blocklistWarning,
        ];
    }

    /**
     * Grab the replacement first; only delete the reviewed files once the grab
     * is accepted. A failure before the grab leaves the current file untouched;
     * a failure after the grab flags the attempt for manual attention.
     *
     * @param  array<string, mixed>  $freshTarget
     * @param  array<string, mixed>  $rawRelease
     */
    private function grabThenDelete(
        SonarrClient|RadarrClient $client,
        ServiceType $serviceType,
        array $freshTarget,
        array $rawRelease,
        MediaReplacementAttempt $attempt,
    ): int {
        $grabAccepted = false;

        try {
            $client->grabRelease($rawRelease);
            $grabAccepted = true;

            return $this->deleteReviewedFiles($client, $serviceType, $freshTarget);
        } catch (Throwable $throwable) {
            $attempt->update([
                'status' => $grabAccepted ? MediaReplacementStatus::NeedsAttention : MediaReplacementStatus::Failed,
                'failure_reason' => $grabAccepted
                    ? 'Grab accepted but current file deletion failed; the old file remains.'
                    : 'Replacement grab failed; the current file was left untouched.',
                'completed_at' => now(),
            ]);

            throw new RuntimeException(
                $grabAccepted
                    ? 'Replacement grabbed but deletion of the reviewed file failed.'
                    : 'Replacement grab was rejected.',
                previous: $throwable,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $freshTarget
     */
    private function deleteReviewedFiles(SonarrClient|RadarrClient $client, ServiceType $serviceType, array $freshTarget): int
    {
        $fileIds = $serviceType === ServiceType::Sonarr
            ? ($freshTarget['episode_file_ids'] ?? [])
            : ($freshTarget['movie_file_ids'] ?? []);

        $deleted = 0;

        foreach (is_array($fileIds) ? $fileIds : [] as $fileId) {
            $id = (int) $fileId;

            if ($serviceType === ServiceType::Sonarr && $client instanceof SonarrClient) {
                $client->deleteEpisodeFile($id);
            } elseif ($client instanceof RadarrClient) {
                $client->deleteMovieFile($id);
            }

            $deleted++;
        }

        return $deleted;
    }

    private function blocklistOriginal(SonarrClient|RadarrClient $client, mixed $historyId, ActionRequest $actionRequest): ?string
    {
        if (! is_int($historyId)) {
            return 'The original release history record was not uniquely identified, so it was not blocklisted.';
        }

        try {
            $client->markHistoryFailed($historyId);

            return null;
        } catch (Throwable $throwable) {
            Log::warning('Media replacement could not blocklist the original release.', [
                'action_request_id' => $actionRequest->id,
                'history_id' => $historyId,
                'exception' => $throwable::class,
            ]);

            return 'The replacement was grabbed but the original release could not be blocklisted.';
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $fresh
     */
    private function sameFiles(array $stored, array $fresh): bool
    {
        if (($fresh['ambiguous'] ?? false) === true) {
            return false;
        }

        if (($stored['service'] ?? null) !== ($fresh['service'] ?? null)) {
            return false;
        }

        $key = ($stored['service'] ?? null) === 'radarr' ? 'movie_file_ids' : 'episode_file_ids';

        return $this->normalizedIds($stored[$key] ?? null) === $this->normalizedIds($fresh[$key] ?? null)
            && $this->normalizedIds($fresh[$key] ?? null) !== [];
    }

    /**
     * @return list<int>
     */
    private function normalizedIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = array_values(array_unique(array_map('intval', $ids)));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
