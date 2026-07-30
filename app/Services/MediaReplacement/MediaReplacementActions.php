<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Enums\MediaReplacementStatus;
use App\Enums\ServiceType;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\Actions\SharedMediaTargetLock;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
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
        private MediaReplacementTracker $mediaReplacementTracker,
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

        // Pin to the exact connection the request was approved against. Multiple
        // same-type connections can be active and their media IDs overlap across
        // instances, so re-resolving the "active" one could act on a different
        // server and delete the wrong file. A supplied-but-missing id (connection
        // deleted after approval) aborts rather than falling through to another
        // instance; only a genuinely absent field falls back for legacy payloads.
        $serviceConnection = $this->resolveConnection($payload, $serviceType);
        $client = $serviceType === ServiceType::Sonarr
            ? new SonarrClient($serviceConnection)
            : new RadarrClient($serviceConnection);

        // Shared installed-file lock: a media replacement must never run
        // concurrently with a Bazarr subtitle download/delete/sync/translate/
        // modify for the same installed file. Keyed on the pinned managing arr
        // connection plus the stable installed-file identity (media type + arr
        // media id) so both executors compute the same key.
        $sharedLocks = $this->acquireSharedTargetLocks($serviceConnection, $serviceType, $storedTarget);

        try {
            return $this->runReplacement(
                $actionRequest,
                $payload,
                $serviceType,
                $serviceConnection,
                $client,
                $storedTarget,
                $fingerprint,
                $requiredLanguages,
            );
        } finally {
            foreach ($sharedLocks as $sharedLock) {
                $sharedLock->release();
            }
        }
    }

    /**
     * Run the revalidate + grab-before-delete replacement under the shared
     * installed-file lock held by the caller.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $storedTarget
     * @param  list<string>|null  $requiredLanguages
     * @return array<string, mixed>
     */
    private function runReplacement(
        ActionRequest $actionRequest,
        array $payload,
        ServiceType $serviceType,
        ServiceConnection $serviceConnection,
        SonarrClient|RadarrClient $client,
        array $storedTarget,
        string $fingerprint,
        ?array $requiredLanguages,
    ): array {
        // Resume, don't re-grab: if a prior run already had its grab accepted
        // (durable grab_accepted_at) but died or failed during the post-grab
        // cleanup, a Retry must NOT re-issue the non-idempotent grab POST
        // (duplicate download). Instead it resumes the remaining destructive
        // steps idempotently (delete tolerates an already-removed file, blocklist
        // is best-effort) so the replacement is actually completed rather than a
        // no-op reported as success.
        $existing = MediaReplacementAttempt::query()
            ->where('action_request_id', $actionRequest->id)
            ->first();

        // A prior run persisted grab_attempted_at (pre-POST) but died before it
        // could record the outcome (SIGKILL/OOM between arr acceptance and the
        // grab_accepted_at save). The grab may well have been accepted, so
        // re-entering the grab path would duplicate the download. Treat it
        // like an indeterminate grab: hand resolution to the Grab/Download
        // webhooks and the reconciliation sweep. Only for executor-owned
        // states — a terminal Failed row means an operator Retry that SHOULD
        // re-grab from scratch.
        if ($existing instanceof MediaReplacementAttempt
            && $existing->grab_attempted_at !== null
            && $existing->grab_accepted_at === null
            && in_array($existing->status, [MediaReplacementStatus::Downloading, MediaReplacementStatus::Requested], true)) {
            if ($existing->cleanup_completed_at === null) {
                $existing->forceFill(['cleanup_completed_at' => now()])->save();
            }

            return [
                'attempt_id' => $existing->id,
                'status' => MediaReplacementStatus::Downloading->value,
                'replacement_initiated' => false,
                'grab_outcome' => 'indeterminate',
                'deleted_files' => 0,
                'message' => 'A previous run attempted the grab but its outcome was never recorded; not re-grabbing. Webhooks and reconciliation will resolve it.',
            ];
        }

        if ($existing instanceof MediaReplacementAttempt && $existing->grab_accepted_at !== null) {
            // `cleanup_completed_at` is the durable evidence of whether the
            // executor finished its post-grab cleanup. If it is set, the run
            // completed (or a webhook produced a real terminal outcome after it)
            // — nothing to do, and re-grabbing would duplicate the download.
            if ($existing->cleanup_completed_at !== null) {
                return [
                    'attempt_id' => $existing->id,
                    'status' => $existing->status->value,
                    'replacement_initiated' => false,
                    'grab_outcome' => 'already_resolved',
                    'deleted_files' => 0,
                    'message' => 'The grab was already accepted and cleanup completed; not re-grabbing or reopening.',
                ];
            }

            // Cleanup is unfinished (a worker crash after the grab, or a deletion
            // failure). Resume the remaining destructive steps idempotently. Reopen
            // the status to `downloading` ONLY when it is still an executor-owned
            // state — via a single CONDITIONAL update, so a webhook that
            // terminalizes the row between the load above and here is never
            // regressed (a check-then-act update could clobber it). completePostGrab
            // then finishes the delete/restore either way, without touching a
            // webhook-produced terminal status.
            MediaReplacementAttempt::query()
                ->whereKey($existing->id)
                ->whereNull('cleanup_completed_at')
                ->where(function (Builder $builder): void {
                    $builder->whereIn('status', [
                        MediaReplacementStatus::Downloading->value,
                        MediaReplacementStatus::Requested->value,
                    ])->orWhere('failure_reason', 'deletion_failed');
                })
                ->update([
                    'status' => MediaReplacementStatus::Downloading->value,
                    'failure_reason' => null,
                    'completed_at' => null,
                ]);
            $existing->refresh();

            return $this->completePostGrab(
                $client,
                $serviceType,
                $serviceConnection,
                is_array($existing->target) ? $existing->target : $storedTarget,
                $existing,
                $payload['original_history_id'] ?? null,
                // Independent, durable suspension state — never inferred from the
                // mutable failure_reason. Blocklist is safe when monitoring was
                // suspended, or when nothing needed suspending.
                blocklistAllowed: $existing->was_monitored !== true || $existing->monitoring_suspended === true,
                actionRequest: $actionRequest,
            );
        }

        // Bust the connection cache BEFORE the pre-grab freshness check: the
        // abort gate below compares installed files against the approval-time
        // snapshot, and a cached getSeries/getEpisodes/getMovie (TTL up to
        // 10 min) could hide a file replaced after approval — the tracker's
        // verifyDownload busts for the same reason.
        ($serviceType === ServiceType::Sonarr
            ? new SonarrCache($serviceConnection)
            : new RadarrCache($serviceConnection))->bustAll();

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
        $selectedCandidate = array_first($stillEligible);

        $rawRelease = $this->replacementCandidateFinder->freshRawRelease($freshTarget, $fingerprint, $serviceConnection);
        throw_if($rawRelease === null, InvalidArgumentException::class, 'Selected release is no longer available.');

        if ($serviceType === ServiceType::Sonarr && ($selectedCandidate['requires_approval'] ?? false) === true) {
            $rawRelease = $this->withSonarrOverride($rawRelease, $freshTarget);
        }

        // Preserve the ORIGINAL monitored state across retries: if a prior run
        // already recorded it as monitored, keep that — a rejected-grab whose
        // restore failed leaves ARR unmonitored, and re-inspecting that current
        // state would otherwise wrongly overwrite was_monitored to false and skip
        // restoration on a later success.
        $wasMonitored = $existing?->was_monitored === true
            || ($freshTarget['monitored'] ?? null) === true;

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
                'grab_attempted_at' => null,
                'grab_accepted_at' => null,
                // Reset the durable cleanup checkpoint too: a prior INDETERMINATE
                // run sets cleanup_completed_at while leaving grab_accepted_at null,
                // so a Retry lands here (not the resume branch) and reuses this row.
                // If the stale timestamp survived, a fast Download during the NEW
                // cleanup would see cleanupDone=true and remonitor the target before
                // the executor blocklists — reopening the competing auto-search race.
                'cleanup_completed_at' => null,
                'was_monitored' => $wasMonitored,
                'monitoring_suspended' => null,
                'verification' => null,
                'failure_reason' => null,
                'started_at' => now(),
                'completed_at' => null,
            ],
        );

        // Suspend monitoring BEFORE the grab — before any Grab/Download webhook
        // can fire and restore it. Only when the target was originally monitored;
        // an already-unmonitored target needs no suppression. This stops the
        // AutoRedownloadFailed search that markHistoryFailed() would otherwise
        // trigger from grabbing a competing, non-rule-vetted release.
        //
        // `didSuspend` = we actually suspended monitoring and therefore own its
        // restoration; false for an already-unmonitored target (nothing to
        // restore) OR when suspension failed. It is persisted so the blocklist
        // decision and the restore decision (this run and any Retry) are driven
        // by durable state, never inferred from the mutable failure_reason.
        $didSuspend = $wasMonitored && $this->unmonitorTarget($client, $serviceType, $freshTarget, $actionRequest);
        $attempt->forceFill(['monitoring_suspended' => $didSuspend])->save();

        // Blocklisting is safe when the target was never monitored, or when we
        // successfully suspended it. A failed suspension of a monitored target
        // must NOT blocklist (that triggers the competing auto-search).
        $blocklistAllowed = ! $wasMonitored || $didSuspend;

        // Durable pre-POST marker: if this process dies between the arr
        // accepting the grab and the grab_accepted_at save below, the retry
        // must find evidence that a grab was already attempted and treat it
        // as indeterminate instead of re-issuing the non-idempotent POST.
        $attempt->forceFill(['grab_attempted_at' => now()])->save();

        $grabOutcome = $this->grab($client, $rawRelease);

        if ($grabOutcome === 'rejected') {
            // Definitive client-side rejection: the release was not accepted and
            // no file was touched. Restore any monitoring we suspended — but
            // ALWAYS terminalize, so a restore failure cannot leave the attempt
            // stuck `downloading` (which would make the job retry the whole grab).
            $restoreFailed = false;

            if ($didSuspend) {
                try {
                    $this->setMonitored($client, $serviceType, $freshTarget, true);
                } catch (Throwable $throwable) {
                    $restoreFailed = true;
                    Log::warning('Media replacement could not restore monitoring after a rejected grab.', [
                        'action_request_id' => $actionRequest->id,
                        'exception' => $throwable::class,
                    ]);
                }
            }

            $this->markTerminal(
                $attempt,
                MediaReplacementStatus::Failed,
                $restoreFailed
                    ? 'Replacement grab was rejected and monitoring could not be restored; needs manual review.'
                    : 'Replacement grab was rejected; the current file was left untouched.',
            );

            throw new RuntimeException('Replacement grab was rejected.');
        }

        if ($grabOutcome === 'indeterminate') {
            // The grab may or may not have been accepted (connection loss / 5xx
            // on the non-idempotent POST). Do NOT delete, blocklist, or fail
            // terminally — leave the attempt `downloading` so the Grab/Download
            // webhooks and the reconciliation sweep resolve it. Mark the cleanup
            // phase complete (there is no further synchronous work here) so the
            // tracker is cleared to restore monitoring if/when the download
            // imports; monitoring stays suspended until then.
            $attempt->forceFill(['cleanup_completed_at' => now()])->save();

            return [
                'attempt_id' => $attempt->id,
                'status' => MediaReplacementStatus::Downloading->value,
                'replacement_initiated' => false,
                'grab_outcome' => 'indeterminate',
                'deleted_files' => 0,
                'message' => 'Grab outcome was indeterminate; tracking it via webhooks and reconciliation.',
            ];
        }

        // Accepted: record the durable grab marker BEFORE the destructive
        // post-grab steps so a Retry resumes (not re-grabs) if this run dies.
        $attempt->forceFill(['grab_accepted_at' => now()])->save();

        return $this->completePostGrab(
            $client,
            $serviceType,
            $serviceConnection,
            $freshTarget,
            $attempt,
            $payload['original_history_id'] ?? null,
            blocklistAllowed: $blocklistAllowed,
            actionRequest: $actionRequest,
        );
    }

    /**
     * Complete the destructive post-grab steps: delete the reviewed file(s),
     * blocklist the old release (when monitoring is suspended), and bust the
     * service cache. Shared by the normal flow and the resume-after-accepted-grab
     * path, so a Retry finishes an interrupted replacement idempotently instead
     * of reporting a no-op as success.
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function completePostGrab(
        SonarrClient|RadarrClient $client,
        ServiceType $serviceType,
        ServiceConnection $serviceConnection,
        array $target,
        MediaReplacementAttempt $mediaReplacementAttempt,
        mixed $originalHistoryId,
        bool $blocklistAllowed,
        ActionRequest $actionRequest,
    ): array {
        $deletedFiles = $this->deleteAfterGrab($client, $serviceType, $target, $mediaReplacementAttempt);

        $blocklistWarning = $this->blocklistAfterGrab($client, $originalHistoryId, $blocklistAllowed, $actionRequest);

        // The executor OWNS restoring monitoring, here — AFTER blocklisting —
        // rather than leaving it to the tracker, which is what closes the
        // blocklist/remonitor race: the tracker will not restore monitoring
        // until cleanup_completed_at is set (below), so throughout this cleanup
        // the target is guaranteed to stay suspended while markHistoryFailed runs.
        // A failed restore leaves monitoring_suspended=true so the tracker retries
        // it once the download imports.
        if ($mediaReplacementAttempt->monitoring_suspended === true) {
            try {
                $this->setMonitored($client, $serviceType, $target, true);
                $mediaReplacementAttempt->forceFill(['monitoring_suspended' => false])->save();
            } catch (Throwable $throwable) {
                Log::warning('Media replacement could not restore monitoring after cleanup; the tracker will retry on import.', [
                    'action_request_id' => $actionRequest->id,
                    'exception' => $throwable::class,
                ]);
            }
        }

        // Mark the cleanup phase complete: only now may the tracker restore
        // monitoring (for any remaining suspension) on a subsequent import event.
        // No status write — a terminal state a webhook set in the meantime survives.
        $mediaReplacementAttempt->forceFill(['cleanup_completed_at' => now()])->save();

        // Finalize a verification a Download webhook recorded WHILE this cleanup was
        // in flight. In that window restoration was deferred to us (the executor),
        // so the tracker deliberately left the attempt pending rather than falsely
        // reporting restore_monitoring_failed. Now that cleanup is done and
        // monitoring restored, terminalize that stored verification. No-op unless
        // such a pending verification exists, so it never clobbers a real webhook
        // terminal outcome.
        $this->mediaReplacementTracker->finalizeAfterCleanup($serviceConnection, $mediaReplacementAttempt);

        $serviceType === ServiceType::Sonarr
            ? new SonarrCache($serviceConnection)->bustAll()
            : new RadarrCache($serviceConnection)->bustAll();

        // Report the PERSISTED status, not a hardcoded 'downloading': on the
        // resume path a webhook may already have terminalized the attempt
        // (verified / needs_attention), and the caller persists this into the
        // ActionRequest result — it must reflect the real state.
        $mediaReplacementAttempt->refresh();

        return [
            'attempt_id' => $mediaReplacementAttempt->id,
            'status' => $mediaReplacementAttempt->status->value,
            'replacement_initiated' => true,
            'deleted_files' => $deletedFiles,
            'blocklist_warning' => $blocklistWarning,
        ];
    }

    /**
     * Blocklist the old release when it is safe. Safety is determined by the
     * cleanup PHASE, not the attempt's status: this runs while cleanup_completed_at
     * is still null, and the tracker will not restore monitoring until that is set,
     * so the target is guaranteed to remain suspended here — blocklisting cannot
     * race a remonitor. It is skipped only when we never successfully suspended
     * monitoring (a monitored target we could not unmonitor), where markHistoryFailed
     * would launch the competing auto-search.
     */
    private function blocklistAfterGrab(
        SonarrClient|RadarrClient $client,
        mixed $originalHistoryId,
        bool $blocklistAllowed,
        ActionRequest $actionRequest,
    ): ?string {
        if (! $blocklistAllowed) {
            return 'Skipped blocklisting the old release because monitoring could not be suspended (avoids a competing auto-search).';
        }

        // Safe regardless of the attempt's status: the cleanup phase is still
        // open (cleanup_completed_at null), so the tracker has not remonitored and
        // the target is guaranteed suspended.
        return $this->blocklistOriginal($client, $originalHistoryId, $actionRequest);
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

        // A pinned connection deactivated after approval must abort rather than
        // run a destructive replacement against a server the operator disabled.
        throw_unless(
            $connection->is_active,
            InvalidArgumentException::class,
            'The approved service connection was deactivated after approval; aborting the replacement.',
        );

        return $connection;
    }

    /**
     * Acquire the shared installed-file lock(s) for the reviewed target so a
     * Bazarr subtitle operation cannot run concurrently against the same file.
     * A season-pack replacement touches several episodes, so one lock per arr
     * media id is taken all-or-nothing; a held lock aborts before any write.
     *
     * @param  array<string, mixed>  $target
     * @return list<Lock>
     */
    private function acquireSharedTargetLocks(
        ServiceConnection $serviceConnection,
        ServiceType $serviceType,
        array $target,
    ): array {
        $mediaType = $serviceType === ServiceType::Sonarr ? 'episode' : 'movie';
        $mediaIds = $serviceType === ServiceType::Sonarr
            ? array_map(intval(...), is_array($target['episode_ids'] ?? null) ? $target['episode_ids'] : [])
            : [(int) ($target['movie_id'] ?? 0)];
        $mediaIds = array_values(array_unique(array_filter($mediaIds, static fn (int $id): bool => $id > 0)));

        $locks = [];

        foreach ($mediaIds as $mediumId) {
            $lock = Cache::lock(
                SharedMediaTargetLock::key($serviceConnection->id, $mediaType, $mediumId),
                SharedMediaTargetLock::TTL_SECONDS,
            );

            if (! $lock->get()) {
                foreach ($locks as $acquired) {
                    $acquired->release();
                }

                throw new RuntimeException('This installed media file is locked by another operation.');
            }

            $locks[] = $lock;
        }

        return $locks;
    }

    /**
     * Flag the release so Sonarr overrides its own rejection of the grab.
     * Sonarr's DownloadRelease validates that `seriesId` and a non-empty
     * `episodeIds` are present on the posted resource whenever `shouldOverride`
     * is set, but its search response only carries the mapped* variants of
     * those fields — posting the resource back untouched 500s with
     * "Value can not be null. (Parameter 'release.SeriesId')". Prefer Sonarr's
     * own mapping, fall back to the replacement target.
     *
     * @param  array<string, mixed>  $rawRelease
     * @param  array<string, mixed>  $freshTarget
     * @return array<string, mixed>
     */
    private function withSonarrOverride(array $rawRelease, array $freshTarget): array
    {
        $rawRelease['shouldOverride'] = true;

        $mappedSeriesId = $rawRelease['mappedSeriesId'] ?? null;
        $rawRelease['seriesId'] = is_int($mappedSeriesId) && $mappedSeriesId > 0
            ? $mappedSeriesId
            : (int) ($freshTarget['series_id'] ?? 0);

        $episodeIds = is_array($rawRelease['episodeIds'] ?? null)
            ? array_values(array_filter($rawRelease['episodeIds'], static fn (mixed $episodeId): bool => is_int($episodeId) && $episodeId > 0))
            : [];

        if ($episodeIds === [] && is_array($freshTarget['episode_ids'] ?? null)) {
            $episodeIds = array_values(array_filter(
                array_map(intval(...), $freshTarget['episode_ids']),
                static fn (int $episodeId): bool => $episodeId > 0,
            ));
        }

        $rawRelease['episodeIds'] = $episodeIds;

        return $rawRelease;
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
        MediaReplacementAttempt $mediaReplacementAttempt,
    ): int {
        try {
            return $this->deleteReviewedFiles($client, $serviceType, $freshTarget);
        } catch (Throwable $throwable) {
            // 'deletion_failed' is the durable marker that this is the executor's
            // own resumable cleanup failure — a Retry reopens ONLY this state,
            // never a webhook-produced terminal result. cleanup_completed_at is
            // left null (we threw before setting it), so the resume knows cleanup
            // did not finish.
            $this->markTerminal($mediaReplacementAttempt, MediaReplacementStatus::NeedsAttention, 'deletion_failed');

            throw new RuntimeException('Replacement grabbed but deletion of the reviewed file failed.', $throwable->getCode(), previous: $throwable);
        }
    }

    /**
     * Transition the attempt to a terminal state only if a webhook has not
     * already moved it to one, so a concurrent verified/needs_attention result
     * is not clobbered by the executor.
     */
    private function markTerminal(MediaReplacementAttempt $mediaReplacementAttempt, MediaReplacementStatus $mediaReplacementStatus, string $reason): void
    {
        $won = MediaReplacementAttempt::query()
            ->whereKey($mediaReplacementAttempt->id)
            ->whereNotIn('status', [
                MediaReplacementStatus::Verified->value,
                MediaReplacementStatus::Failed->value,
                MediaReplacementStatus::NeedsAttention->value,
            ])
            ->update([
                'status' => $mediaReplacementStatus->value,
                'failure_reason' => $reason,
                'completed_at' => now(),
            ]) === 1;

        // Only announce a terminal state this call actually won, so a correlated
        // subtitle case moves to needs_review without a concurrent webhook result
        // being clobbered or re-announced.
        if ($won) {
            $mediaReplacementAttempt->refresh();
            event(new MediaReplacementAttemptChanged($mediaReplacementAttempt));
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

            try {
                if ($serviceType === ServiceType::Sonarr && $client instanceof SonarrClient) {
                    $client->deleteEpisodeFile($id);
                } elseif ($client instanceof RadarrClient) {
                    $client->deleteMovieFile($id);
                }
            } catch (RequestException $requestException) {
                // A 404 means the file is already gone — idempotent for the
                // resume path (a prior run deleted it before dying). Any other
                // error is a real deletion failure and must surface.
                throw_if($requestException->response->status() !== 404, $requestException);
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
