<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementStatus;
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
 *
 * "Not the release this attempt vetted" is not the same as "not ours". Another
 * replacement can be in flight on the same connection, and its download is
 * another vetted release, not a competitor — see siblingDownloadIds() for the
 * keep set that protects it and for the limits of what that set can know.
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

        // Reduce our target once, not once per queue row.
        $targetIdentity = $this->targetIdentity($target, $isRadarr);
        $siblingDownloadIds = $this->siblingDownloadIds($serviceConnection, $mediaReplacementAttempt);

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

            if (! $this->overlaps($targetIdentity, $this->recordIdentity($record, $isRadarr))) {
                continue;
            }

            $recordDownloadId = trim((string) ($record['downloadId'] ?? ''));

            // Our own download, positively identified. Both sides are trimmed:
            // an accidental difference in padding must not be the reason we
            // delete the replacement's own download.
            if ($recordDownloadId === $ourDownloadId) {
                continue;
            }

            // Another live replacement's own download. It is not a competitor,
            // and removing it would strand that attempt waiting for an import
            // that can no longer arrive. $siblingDownloadIds never contains a
            // blank, so a row reporting no download id is not spared here.
            if ($recordDownloadId !== '' && in_array($recordDownloadId, $siblingDownloadIds, true)) {
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
     * Reduce a stored attempt target to the pair the overlap rule compares: the
     * parent media id, and the episode ids it covers (always empty for Radarr,
     * which has no episode dimension).
     *
     * Which shape to read is decided from the target's OWN `service` field, the
     * way MediaReplacementTracker::attemptTargetId() decides it, so the two rules
     * can never disagree about the same stored target. The connection's type is
     * the fallback, because it is the only signal a legacy target without a
     * `service` field carries.
     *
     * @param  array<string, mixed>  $target
     * @return array{parentId: ?int, episodeIds: list<int>}
     */
    private function targetIdentity(array $target, bool $connectionIsRadarr): array
    {
        $service = mb_strtolower(trim((string) ($target['service'] ?? '')));
        $isRadarr = $service === '' ? $connectionIsRadarr : $service === 'radarr';

        if ($isRadarr) {
            return ['parentId' => $this->positiveInt($target['movie_id'] ?? null), 'episodeIds' => []];
        }

        return [
            'parentId' => $this->positiveInt($target['series_id'] ?? null),
            'episodeIds' => $this->episodeIds($target['episode_ids'] ?? null),
        ];
    }

    /**
     * The same reduction for a download-queue row, whose field names and shape
     * differ from a stored target's.
     *
     * @param  array<string, mixed>  $record
     * @return array{parentId: ?int, episodeIds: list<int>}
     */
    private function recordIdentity(array $record, bool $isRadarr): array
    {
        if ($isRadarr) {
            return ['parentId' => $this->positiveInt($record['movieId'] ?? null), 'episodeIds' => []];
        }

        return [
            'parentId' => $this->positiveInt($record['seriesId'] ?? null),
            'episodeIds' => $this->episodeIds([
                $record['episodeId'] ?? null,
                ...(is_array($record['episodeIds'] ?? null) ? $record['episodeIds'] : []),
            ]),
        ];
    }

    /**
     * Whether two reduced identities refer to media this sweep should treat as
     * the same thing. The single definition of that rule; `left` is our target
     * and `right` a queue row, and the null-parent bail is asymmetric because a
     * target we cannot identify must match nothing rather than everything.
     *
     * @param  array{parentId: ?int, episodeIds: list<int>}  $left
     * @param  array{parentId: ?int, episodeIds: list<int>}  $right
     */
    private function overlaps(array $left, array $right): bool
    {
        if ($left['parentId'] === null || $left['parentId'] !== $right['parentId']) {
            return false;
        }

        // Sonarr's queue does carry a top-level episodeId per row, season packs
        // included, so this is the degenerate case: a target stored without
        // episode ids, or a row whose episode mapping is missing or unresolved.
        // Series identity is then all we have. Radarr always lands here, having
        // no episode dimension at all.
        if ($left['episodeIds'] === [] || $right['episodeIds'] === []) {
            return true;
        }

        return array_intersect($left['episodeIds'], $right['episodeIds']) !== [];
    }

    /**
     * The download ids of every OTHER in-flight replacement attempt on this
     * connection. A queue row carrying one of these is another attempt's vetted
     * release: it is not a competitor, and removing it — with
     * removeFromClient: true — would strand that attempt waiting for an import
     * that can no longer arrive.
     *
     * Deliberately NOT narrowed by target overlap, and that is safe in one
     * direction only. The set holds download IDS, and a download id names one
     * download in the client, so only the sibling's own queue rows can ever
     * carry it — a genuine competitor cannot match this set however wide it
     * gets. Narrowing, by contrast, strands siblings: a batch/season pack shows
     * up as one row per contained episode all sharing the pack's single
     * download id, so an episode-precise set would fail to protect the row
     * Sonarr attributes to OUR episode and removing it would take the whole
     * pack. There is no over-spare risk to trade against.
     *
     * What the set covers and what it does not:
     * - Only non-terminal siblings. A settled attempt is finished and nothing is
     *   waiting on its download, so it earns no protection.
     * - Only siblings that have actually recorded a download id. A blank one
     *   contributes NOTHING rather than widening the set, which would otherwise
     *   spare every row that reports no download id of its own and disarm the
     *   sweep — the same match-all-on-a-missing-identifier failure the arming
     *   invariant in run() exists to prevent.
     * - It covers downloads THIS application started and recorded. It says
     *   nothing about a download a user or another tool queued by hand: such a
     *   row is indistinguishable from an arr auto-redownload here and is still
     *   removed.
     *
     * @return list<string>
     */
    private function siblingDownloadIds(
        ServiceConnection $serviceConnection,
        MediaReplacementAttempt $mediaReplacementAttempt,
    ): array {
        $siblings = MediaReplacementAttempt::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->whereKeyNot($mediaReplacementAttempt->id)
            ->whereNotIn('status', MediaReplacementStatus::terminalValues())
            ->whereNotNull('download_id')
            ->get();

        $downloadIds = [];

        foreach ($siblings as $sibling) {
            // Reuses the one definition of "a blank download id is unknown",
            // rather than trusting whereNotNull to have caught every empty form.
            $downloadId = $this->downloadId($sibling);

            if ($downloadId === null) {
                continue;
            }

            $downloadIds[$downloadId] = $downloadId;
        }

        return array_values($downloadIds);
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
     *
     * The value is returned trimmed, because it is the value the keep-guard
     * compares. Deciding emptiness on the trimmed form while arming the sweep
     * with the padded one would let a stored " DL-X " arm a sweep that can
     * never match the queue's "DL-X", leaving our own download protected by
     * nothing but the title — the single point of failure requiring a download
     * id was meant to remove.
     */
    private function downloadId(MediaReplacementAttempt $mediaReplacementAttempt): ?string
    {
        $downloadId = $mediaReplacementAttempt->download_id;

        if (! is_string($downloadId) || trim($downloadId) === '') {
            return null;
        }

        return trim($downloadId);
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
