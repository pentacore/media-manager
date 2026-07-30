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
 *
 * The sweep is armed only once the attempt knows its own download id, which the
 * Grab webhook records. Identity may never rest on the release title alone: see
 * the guard in run() for why. A missing download id therefore means no sweep at
 * all, and the competing download is left for a later pass to clean up.
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
        $ourDownloadId = $this->downloadId($mediaReplacementAttempt);

        // Only our own download id may arm the sweep. The title cannot: a queue
        // row's title is the download client's name for the job, not the
        // indexer release title the candidate was built from, so a client-side
        // rename or a mere separator difference would break the equality that
        // is supposed to protect us and we would DELETE the replacement we just
        // grabbed — with removeFromClient: true, destroying it. Failing safe
        // means a lost Grab webhook produces no sweep rather than a deleted
        // replacement; the Grab-webhook and delayed passes both run later, once
        // the download id exists. The title stays a keep-guard below, where it
        // can only ever spare a row, never select one for removal.
        if ($ourDownloadId === null) {
            return 0;
        }

        $isRadarr = $serviceConnection->type === ServiceType::Radarr;
        $client = $isRadarr ? new RadarrClient($serviceConnection) : new SonarrClient($serviceConnection);

        $queue = $client->getQueue(['pageSize' => 200]);
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

            // Our own download, positively identified.
            if ((string) ($record['downloadId'] ?? '') === $ourDownloadId) {
                continue;
            }

            // Belt and braces: a row the client renamed away from our download
            // id but that still carries the vetted release title is spared. A
            // title can only keep a row here, never condemn one.
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

        $recordEpisodeIds = $this->episodeIds([
            $record['episodeId'] ?? null,
            ...(is_array($record['episodeIds'] ?? null) ? $record['episodeIds'] : []),
        ]);

        // Sonarr's queue does carry a top-level episodeId per row, season packs
        // included, so this is the degenerate case: a target stored without
        // episode ids, or a row whose episode mapping is missing or unresolved.
        // Series identity is then all we have, and a competing download for the
        // series we are replacing into is worth removing.
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

    /**
     * Announcing a removal must never change or truncate the sweep itself.
     * A failing notification backend would otherwise propagate to sweep()'s
     * catch, which would report zero removals despite rows having really been
     * removed and would skip every remaining queue record.
     */
    private function notify(
        ServiceConnection $serviceConnection,
        MediaReplacementAttempt $mediaReplacementAttempt,
        string $removedTitle,
    ): void {
        try {
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
        } catch (Throwable $throwable) {
            Log::warning('Could not notify admins about a removed competing grab.', [
                'attempt_id' => $mediaReplacementAttempt->id,
                'queue_item_title' => $removedTitle,
                'exception' => $throwable::class,
            ]);
        }
    }

    /**
     * Our own download id, or null when the Grab webhook has not recorded one
     * yet. A blank value counts as unknown: it would otherwise match every
     * queue row that reports no download id of its own.
     */
    private function downloadId(MediaReplacementAttempt $mediaReplacementAttempt): ?string
    {
        $downloadId = $mediaReplacementAttempt->download_id;

        if (! is_string($downloadId) || trim($downloadId) === '') {
            return null;
        }

        return $downloadId;
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
