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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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
            InvalidArgumentException::class,
            sprintf('MediaReplacementActions cannot execute type "%s"', $actionRequest->type),
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
            ? array_values(array_filter($payload['required_languages'], is_string(...)))
            : null;

        // Do not re-grab a release whose grab was already accepted on a prior
        // run (e.g. Retry after a post-grab deletion failure). The grab POST is
        // a non-idempotent external side effect, so re-issuing it would start a
        // duplicate download. The in-flight download is resolved by webhooks /
        // the reconciliation sweep.
        $existing = MediaReplacementAttempt::query()
            ->where('action_request_id', $actionRequest->id)
            ->first();

        if ($existing instanceof MediaReplacementAttempt && $existing->grab_accepted_at !== null) {
            return [
                'attempt_id' => $existing->id,
                'status' => $existing->status->value,
                'replacement_initiated' => false,
                'grab_outcome' => 'already_grabbed',
                'deleted_files' => 0,
                'message' => 'A grab was already accepted for this request; not re-grabbing to avoid a duplicate download.',
            ];
        }

        // Pin to the exact connection the request was approved against. Multiple
        // same-type connections can be active and their media IDs overlap across
        // instances, so re-resolving the "active" one could act on a different
        // server and delete the wrong file. A supplied-but-missing id (connection
        // deleted after approval) aborts rather than falling through to another
        // instance; only a genuinely absent field falls back for legacy payloads.
        $serviceConnection = $this->resolveConnection($payload, $serviceType);

        $freshTarget = $this->mediaFileInspector->inspectFromSnapshot($storedTarget, $serviceConnection);
        throw_unless(
            $this->sameFiles($storedTarget, $freshTarget),
            InvalidArgumentException::class,
            'Installed media files changed after approval; aborting replacement.',
        );

        $eligible = $this->replacementCandidateFinder->find($freshTarget, $requiredLanguages, 10, $serviceConnection);
        $stillEligible = array_filter(
            $eligible['candidates'],
            static fn (array $candidate): bool => ($candidate['fingerprint'] ?? null) === $fingerprint,
        );
        throw_if($stillEligible === [], InvalidArgumentException::class, 'Selected release is no longer eligible.');

        $rawRelease = $this->replacementCandidateFinder->freshRawRelease($freshTarget, $fingerprint, $serviceConnection);
        throw_if($rawRelease === null, InvalidArgumentException::class, 'Selected release is no longer available.');

        $client = $serviceType === ServiceType::Sonarr
            ? new SonarrClient($serviceConnection)
            : new RadarrClient($serviceConnection);

        $wasMonitored = ($freshTarget['monitored'] ?? null) === true;

        // Claim the attempt as `downloading` BEFORE the grab. Keying updateOrCreate
        // on the unique action_request_id makes Action-Queue Retry idempotent — a
        // prior failed attempt row is reset and reused instead of hitting a
        // duplicate-key error. Setting `downloading` here (not after the grab)
        // means a fast Grab/Download webhook that advances the attempt to a
        // terminal state is never regressed by a later executor write.
        $attempt = MediaReplacementAttempt::updateOrCreate(
            ['action_request_id' => $actionRequest->id],
            [
                'service_connection_id' => $serviceConnection->id,
                'status' => MediaReplacementStatus::Downloading,
                'scope' => (string) ($payload['scope'] ?? ($freshTarget['scope'] ?? 'movie')),
                'target' => $freshTarget,
                'candidate_fingerprint' => $fingerprint,
                'candidate' => is_array($payload['candidate'] ?? null) ? $payload['candidate'] : [],
                'required_languages' => $requiredLanguages ?? $eligible['effective_languages'],
                'download_id' => null,
                'grab_accepted_at' => null,
                'was_monitored' => $wasMonitored,
                'verification' => null,
                'failure_reason' => null,
                'started_at' => now(),
                'completed_at' => null,
            ],
        );

        // Suspend monitoring BEFORE the grab — before any Grab/Download webhook
        // can fire and restore it — so the executor never writes monitoring after
        // a webhook could have. Only when the target was originally monitored; an
        // already-unmonitored target needs no suppression. This stops the
        // AutoRedownloadFailed search that markHistoryFailed() would otherwise
        // trigger from grabbing a competing, non-rule-vetted release.
        $monitoringSuspended = $wasMonitored
            ? $this->unmonitorTarget($client, $serviceType, $freshTarget, $actionRequest)
            : true;

        $grabOutcome = $this->grab($client, $rawRelease);

        if ($grabOutcome === 'rejected') {
            // Definitive client-side rejection: the release was not accepted and
            // no file was touched. Restore any monitoring we suspended, then fail.
            if ($wasMonitored && $monitoringSuspended) {
                $this->setMonitored($client, $serviceType, $freshTarget, true);
            }

            $this->markTerminal($attempt, MediaReplacementStatus::Failed, 'Replacement grab was rejected; the current file was left untouched.');

            throw new RuntimeException('Replacement grab was rejected.');
        }

        if ($grabOutcome === 'indeterminate') {
            // The grab may or may not have been accepted (connection loss / 5xx
            // on the non-idempotent POST). Do NOT delete, blocklist, or fail
            // terminally — leave the attempt `downloading` so the Grab/Download
            // webhooks and the reconciliation sweep resolve it. Monitoring stays
            // suspended; the tracker restores it if the download imports.
            return [
                'attempt_id' => $attempt->id,
                'status' => MediaReplacementStatus::Downloading->value,
                'replacement_initiated' => false,
                'grab_outcome' => 'indeterminate',
                'deleted_files' => 0,
                'message' => 'Grab outcome was indeterminate; tracking it via webhooks and reconciliation.',
            ];
        }

        // Accepted: record the durable grab marker so a retry never re-grabs.
        $attempt->forceFill(['grab_accepted_at' => now()])->save();

        $deletedFiles = $this->deleteAfterGrab($client, $serviceType, $freshTarget, $attempt);

        // Only blocklist the old release when monitoring is genuinely suspended.
        // markHistoryFailed() triggers the arr's auto-redownload search; running
        // it while the target is still monitored would grab a competing release.
        $blocklistWarning = $monitoringSuspended
            ? $this->blocklistOriginal($client, $payload['original_history_id'] ?? null, $actionRequest)
            : 'Skipped blocklisting the old release because monitoring could not be suspended (avoids a competing auto-search).';

        // No status write here: the attempt is already `downloading` (set before
        // the grab), so a terminal state a webhook set in the meantime survives.
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
     * Resolve the connection the request was approved against. Aborts when a
     * pinned id is supplied but no longer resolves, or resolves to a different
     * service type; only a genuinely absent field falls back to the active one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveConnection(array $payload, ServiceType $serviceType): ServiceConnection
    {
        if (! array_key_exists('service_connection_id', $payload) || $payload['service_connection_id'] === null) {
            return ServiceConnection::resolveActive($serviceType);
        }

        $connectionId = (int) $payload['service_connection_id'];
        $connection = $connectionId > 0 ? ServiceConnection::find($connectionId) : null;

        throw_if(
            $connection === null,
            InvalidArgumentException::class,
            'The approved service connection no longer exists; aborting to avoid acting on a different server.',
        );

        throw_unless(
            $connection->type === $serviceType,
            InvalidArgumentException::class,
            'The approved service connection type does not match the replacement service; aborting.',
        );

        return $connection;
    }

    /**
     * Classify the grab outcome without side effects on the attempt:
     *  - 'accepted'      — the arr accepted the release.
     *  - 'rejected'      — an explicit client-side (4xx) rejection: definitely
     *                      not accepted, so no file was touched.
     *  - 'indeterminate' — connection loss or a server error (5xx) on the
     *                      non-idempotent POST: the grab may already have been
     *                      accepted, so it must stay trackable rather than fail.
     *
     * @param  array<string, mixed>  $rawRelease
     */
    private function grab(SonarrClient|RadarrClient $client, array $rawRelease): string
    {
        try {
            $client->grabRelease($rawRelease);

            return 'accepted';
        } catch (ConnectionException $connectionException) {
            Log::warning('Media replacement grab outcome indeterminate (connection error); leaving the attempt trackable.', [
                'exception' => $connectionException::class,
            ]);

            return 'indeterminate';
        } catch (RequestException $requestException) {
            if ($requestException->response->clientError()) {
                return 'rejected';
            }

            Log::warning('Media replacement grab outcome indeterminate (server error); leaving the attempt trackable.', [
                'status' => $requestException->response->status(),
            ]);

            return 'indeterminate';
        }
    }

    /**
     * Delete the reviewed file(s) after an accepted grab. A failure here means
     * the replacement was grabbed but the old file could not be removed.
     *
     * @param  array<string, mixed>  $freshTarget
     */
    private function deleteAfterGrab(
        SonarrClient|RadarrClient $client,
        ServiceType $serviceType,
        array $freshTarget,
        MediaReplacementAttempt $attempt,
    ): int {
        try {
            return $this->deleteReviewedFiles($client, $serviceType, $freshTarget);
        } catch (Throwable $throwable) {
            $this->markTerminal($attempt, MediaReplacementStatus::NeedsAttention, 'Grab accepted but current file deletion failed; the old file remains.');

            throw new RuntimeException('Replacement grabbed but deletion of the reviewed file failed.', previous: $throwable);
        }
    }

    /**
     * Transition the attempt to a terminal state only if a webhook has not
     * already moved it to one, so a concurrent verified/needs_attention result
     * is not clobbered by the executor.
     */
    private function markTerminal(MediaReplacementAttempt $attempt, MediaReplacementStatus $status, string $reason): void
    {
        MediaReplacementAttempt::query()
            ->whereKey($attempt->id)
            ->whereNotIn('status', [
                MediaReplacementStatus::Verified->value,
                MediaReplacementStatus::Failed->value,
                MediaReplacementStatus::NeedsAttention->value,
            ])
            ->update([
                'status' => $status->value,
                'failure_reason' => $reason,
                'completed_at' => now(),
            ]);
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

    /**
     * Suspend monitoring on the reviewed target so the arr's auto-redownload
     * search cannot grab a competing release. Returns whether it succeeded;
     * callers must skip blocklisting when it did not.
     *
     * @param  array<string, mixed>  $freshTarget
     */
    private function unmonitorTarget(
        SonarrClient|RadarrClient $client,
        ServiceType $serviceType,
        array $freshTarget,
        ActionRequest $actionRequest,
    ): bool {
        try {
            $this->setMonitored($client, $serviceType, $freshTarget, false);

            return true;
        } catch (Throwable $throwable) {
            Log::warning('Media replacement could not suspend monitoring; the old release will not be blocklisted.', [
                'action_request_id' => $actionRequest->id,
                'exception' => $throwable::class,
            ]);

            return false;
        }
    }

    /**
     * Set the target's monitored flag. Throws on failure so callers can react.
     *
     * @param  array<string, mixed>  $target
     */
    private function setMonitored(SonarrClient|RadarrClient $client, ServiceType $serviceType, array $target, bool $monitored): void
    {
        if ($serviceType === ServiceType::Sonarr && $client instanceof SonarrClient) {
            $episodeIds = array_values(array_map(intval(...), is_array($target['episode_ids'] ?? null) ? $target['episode_ids'] : []));

            if ($episodeIds !== []) {
                $client->setEpisodesMonitored($episodeIds, $monitored);
            }

            return;
        }

        if ($client instanceof RadarrClient) {
            $movieId = (int) ($target['movie_id'] ?? 0);

            if ($movieId > 0) {
                $client->setMovieMonitored($movieId, $monitored);
            }
        }
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

        $normalized = array_values(array_unique(array_map(intval(...), $ids)));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
