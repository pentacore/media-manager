<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Removes download-queue items that the arr grabbed for a replacement target
 * but that are not the release this attempt vetted.
 *
 * Blocklisting the old release makes the arr queue its own re-search
 * asynchronously; suspending monitoring suppresses the resulting grab, but any
 * grab that still slips through would download in parallel with the
 * replacement. This sweeper is the outcome-based cleanup for that case.
 */
final readonly class CompetingGrabSweeper
{
    /**
     * Remove every queue item on the attempt's target that is not the vetted
     * release. Returns how many were removed. Never throws — a sweep failure
     * must not abort the cleanup or webhook handling that called it.
     */
    public function sweep(ServiceConnection $serviceConnection, MediaReplacementAttempt $mediaReplacementAttempt): int
    {
        try {
            return $this->run($serviceConnection, $mediaReplacementAttempt);
        } catch (Throwable $throwable) {
            Log::warning('Competing-grab sweep failed.', [
                'attempt_id' => $mediaReplacementAttempt->id,
                'service_connection_id' => $serviceConnection->id,
                'exception' => $throwable::class,
            ]);

            return 0;
        }
    }

    private function run(ServiceConnection $serviceConnection, MediaReplacementAttempt $mediaReplacementAttempt): int
    {
        $target = is_array($mediaReplacementAttempt->target) ? $mediaReplacementAttempt->target : [];
        $vettedTitle = $this->normalizeTitle((string) ($mediaReplacementAttempt->candidate['title'] ?? ''));
        $ourDownloadId = is_string($mediaReplacementAttempt->download_id) ? $mediaReplacementAttempt->download_id : null;

        // Without either identifier we cannot tell our own download apart from
        // a competing one, and removing the wrong item would cancel the
        // replacement itself. Refusing to act is the only safe answer.
        if ($vettedTitle === '' && $ourDownloadId === null) {
            return 0;
        }

        $isRadarr = $serviceConnection->type === ServiceType::Radarr;
        $client = $isRadarr ? new RadarrClient($serviceConnection) : new SonarrClient($serviceConnection);

        $queue = $client->getQueue($isRadarr
            ? ['pageSize' => 200]
            : ['pageSize' => 200, 'includeEpisode' => 'true']);
        $records = is_array($queue['records'] ?? null) ? $queue['records'] : [];

        $removed = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $queueItemId = $this->positiveInt($record['id'] ?? null);

            if ($queueItemId === null) {
                continue;
            }

            if (! $this->matchesTarget($record, $target, $isRadarr)) {
                continue;
            }

            if ($ourDownloadId !== null && (string) ($record['downloadId'] ?? '') === $ourDownloadId) {
                continue;
            }

            if ($vettedTitle !== '' && $this->normalizeTitle((string) ($record['title'] ?? '')) === $vettedTitle) {
                continue;
            }

            if ($this->remove($client, $queueItemId, $mediaReplacementAttempt)) {
                $removed++;
                $this->notify($serviceConnection, $mediaReplacementAttempt, (string) ($record['title'] ?? 'unknown release'));
            }
        }

        return $removed;
    }

    private function remove(
        SonarrClient|RadarrClient $client,
        int $queueItemId,
        MediaReplacementAttempt $mediaReplacementAttempt,
    ): bool {
        try {
            // blocklist: false — the competing release is not bad, it simply is
            // not the one we vetted; banning it would deny the user a release
            // they may legitimately want later.
            $client->removeQueueItem($queueItemId, removeFromClient: true, blocklist: false, skipRedownload: true);

            Log::info('Removed a competing grab for a media replacement target.', [
                'attempt_id' => $mediaReplacementAttempt->id,
                'queue_item_id' => $queueItemId,
            ]);

            return true;
        } catch (Throwable $throwable) {
            Log::warning('Could not remove a competing grab.', [
                'attempt_id' => $mediaReplacementAttempt->id,
                'queue_item_id' => $queueItemId,
                'exception' => $throwable::class,
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $target
     */
    private function matchesTarget(array $record, array $target, bool $isRadarr): bool
    {
        if ($isRadarr) {
            $movieId = $this->positiveInt($target['movie_id'] ?? null);

            return $movieId !== null && $this->positiveInt($record['movieId'] ?? null) === $movieId;
        }

        $seriesId = $this->positiveInt($target['series_id'] ?? null);

        if ($seriesId === null || $this->positiveInt($record['seriesId'] ?? null) !== $seriesId) {
            return false;
        }

        $targetEpisodeIds = $this->episodeIds($target['episode_ids'] ?? null);

        // A season-pack or series-level queue row carries no episode id. It
        // still competes for the same series, so treat it as a match.
        $recordEpisodeIds = $this->episodeIds([
            $record['episodeId'] ?? null,
            ...(is_array($record['episodeIds'] ?? null) ? $record['episodeIds'] : []),
        ]);

        if ($targetEpisodeIds === [] || $recordEpisodeIds === []) {
            return true;
        }

        return array_intersect($targetEpisodeIds, $recordEpisodeIds) !== [];
    }

    /**
     * @return list<int>
     */
    private function episodeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $episodeIds = [];

        foreach ($ids as $id) {
            $episodeId = $this->positiveInt($id);

            if ($episodeId !== null) {
                $episodeIds[$episodeId] = $episodeId;
            }
        }

        return array_values($episodeIds);
    }

    private function notify(
        ServiceConnection $serviceConnection,
        MediaReplacementAttempt $mediaReplacementAttempt,
        string $removedTitle,
    ): void {
        $admins = User::query()->where('role', UserRole::Admin)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new MediaReplacementStatusChanged(
            service: $serviceConnection->type->value,
            title: (string) ($mediaReplacementAttempt->candidate['title'] ?? 'Media replacement'),
            message: sprintf(
                'Removed a competing download the service started for this target: "%s".',
                $removedTitle,
            ),
            level: 'warning',
        ));
    }

    private function normalizeTitle(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d+$/D', trim($value)) !== 1) {
            return null;
        }

        $integer = (int) trim($value);

        return $integer > 0 ? $integer : null;
    }
}
