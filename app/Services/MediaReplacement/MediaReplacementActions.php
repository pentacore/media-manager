<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Enums\MediaReplacementStatus;
use App\Enums\ServiceType;
use App\Jobs\SweepCompetingGrabs;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\Database\Query\Builder;
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
        private MediaReplacementTracker $mediaReplacementTracker,
        private CompetingGrabSweeper $competingGrabSweeper,
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

            // Rewrite started_at BEFORE anything else this run does. It is the age
            // basis media-replacement:reconcile filters both of its passes on, and
            // until now it recorded the ORIGINAL attempt — so an operator Retry of a
            // days-old row ran a live executor that the hourly reconcile treated as
            // ancient and fair game. The damaging order is: this run reads the target
            // as suspended, reconcile restores monitoring and clears the flag,
            // blocklistAfterGrab then blocklists a MONITORED target, and the arr's
            // queued re-search grabs a competitor. A resumed attempt genuinely is a
            // download attempt starting now, so this is the honest value as well as
            // the safe one — and it applies whether or not the conditional reopen
            // below matches, which is what covers a row that stays terminal.
            //
            // It only helps because both reconcile passes re-evaluate the age against
            // current state rather than trusting their selecting query: the timeout
            // pass inside its conditional update, the repair pass on a fresh read. What
            // it buys in each case is exclusion from the moment this write commits, not
            // exclusion outright — a timeout update or a repair read that got in first
            // is still ahead of us and can issue its monitor PUT afterwards. Doing this
            // write FIRST is what makes that window as narrow as it can be made without
            // an atomic claim on the row; see blocklistAfterGrab()'s docblock.
            $existing->forceFill(['started_at' => now()])->save();

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

            $resumeTarget = is_array($existing->target) ? $existing->target : $storedTarget;

            // RE-ASSERT the suspension instead of trusting the persisted flag for the
            // BLOCKLIST DECISION. This row has been sitting in the database since the
            // run that died, and other actors can have remonitored the target since
            // (the reconciliation repair pass does exactly that on settled attempts).
            // Trusting a flag another process may have cleared is a
            // time-of-check/time-of-use bug: it would blocklist a target that is
            // monitored again, and the arr's queued re-search would then grab a
            // competitor. The unmonitor PUT is idempotent, so re-issuing it is cheap
            // and makes the blocklist decision depend on what is true NOW.
            $wasMonitored = $existing->was_monitored === true;
            $didSuspend = $wasMonitored && $this->unmonitorTarget($client, $serviceType, $resumeTarget, $actionRequest);

            // The PERSISTED flag answers a different question — "does someone still
            // owe this target a remonitor?" — and must never lose a `true` an earlier
            // run earned. A failed unmonitor PUT is not evidence that the target is
            // monitored; it is evidence of nothing, and arr trouble is a likely reason
            // the earlier run died in the first place. Writing $didSuspend here would
            // therefore clear the obligation precisely when it is most likely still
            // outstanding, and every actor that could discharge it stands down on a
            // false flag: restoreSuspendedMonitoring() returns success without an arr
            // call, verifyDownload() sees nothing to restore, and both reconciliation
            // passes select on monitoring_suspended = true. The target would stop
            // receiving upgrades permanently.
            $existing->forceFill([
                'monitoring_suspended' => $didSuspend || ($wasMonitored && $existing->monitoring_suspended === true),
            ])->save();

            return $this->completePostGrab(
                $client,
                $serviceType,
                $serviceConnection,
                $resumeTarget,
                $existing,
                $payload['original_history_id'] ?? null,
                // Same rule as the fresh path: safe when nothing needed suspending,
                // or when we just suspended it ourselves. A monitored target we
                // could not suspend must not be blocklisted.
                blocklistAllowed: ! $wasMonitored || $didSuspend,
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

        // Carry forward a restore a prior run still OWES, for the same reason
        // was_monitored is preserved above and the resume path re-asserts it: a
        // rejected grab whose restore PUT failed leaves the target genuinely
        // unmonitored with the flag set, and the reset below would erase the only
        // durable record of that. Nothing would then put monitoring back —
        // restoreSuspendedMonitoring() reports success without an arr call,
        // verifyDownload() sees nothing to restore, and both reconciliation passes
        // select on monitoring_suspended = true.
        $owedRestore = $wasMonitored && $existing?->monitoring_suspended === true;

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
                // Not blindly null: an unlifted suspension a prior run left behind is
                // an obligation, not stale state (see $owedRestore above).
                'monitoring_suspended' => $owedRestore ? true : null,
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
        // `didSuspend` = THIS run suspended monitoring; false for an
        // already-unmonitored target (nothing to suppress) OR when the PUT failed. It
        // drives the blocklist decision below, which must depend on what is true now.
        //
        // The persisted flag answers the different question of whether a remonitor is
        // still owed, so it is the union of this run's suspension and one inherited
        // from a prior run. A failed PUT is not evidence that the target is monitored,
        // and writing $didSuspend alone would drop an inherited obligation with no
        // actor left to discharge it.
        $didSuspend = $wasMonitored && $this->unmonitorTarget($client, $serviceType, $freshTarget, $actionRequest);
        $attempt->forceFill(['monitoring_suspended' => $didSuspend || $owedRestore])->save();

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

            // Attempt the restore whenever one is OWED, not only when this run is what
            // took monitoring away: an inherited suspension is just as real, and
            // skipping it here was how a Retry could turn a recoverable state into a
            // permanent one.
            if ($didSuspend || $owedRestore) {
                try {
                    $this->setMonitored($client, $serviceType, $freshTarget, true);
                    // Record the restore. Leaving the flag set would advertise a
                    // suspension that no longer exists, and the reconciliation
                    // repair pass would later re-issue a pointless monitor PUT (or
                    // warn that monitoring is still off) for every rejected grab.
                    $attempt->forceFill(['monitoring_suspended' => false])->save();
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
     * blocklist the old release (when monitoring is suspended), sweep any
     * competing grab, and bust the service cache. Monitoring is deliberately
     * left suspended — the import event restores it. Shared by the normal flow
     * and the resume-after-accepted-grab path, so a Retry finishes an
     * interrupted replacement idempotently instead of reporting a no-op as
     * success.
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

        // Blocklisting makes the arr QUEUE its own re-search; the search itself
        // runs seconds later, well after this method returns. Monitoring must
        // therefore stay suspended past the end of this run — the import event
        // owns the restore (MediaReplacementTracker), and the reconciliation
        // sweep is the backstop if no import ever arrives. Restoring here is
        // what previously let the queued search grab a competing release and
        // start a second download.
        $competingGrabsRemoved = $this->competingGrabSweeper->sweep($serviceConnection, $mediaReplacementAttempt);

        // Mark the cleanup phase complete: only now may the tracker restore
        // monitoring on a subsequent import event. No status write — a terminal
        // state a webhook set in the meantime survives.
        $mediaReplacementAttempt->forceFill(['cleanup_completed_at' => now()])->save();

        // Backstop for a re-search that lands after the synchronous sweep above, and
        // for a lost Grab webhook. Armed only when the blocklist actually SUCCEEDED,
        // which is exactly when blocklistAfterGrab returns null. $blocklistAllowed is
        // the wrong gate: it also holds on the two paths where no blocklist ran at all
        // — a non-int original_history_id, and markHistoryFailed throwing.
        //
        // A queued re-search is not the only way a competitor can appear: where the
        // blocklist was declined, monitoring is still ON and the old file has just been
        // deleted, so an RSS sync can grab one. These passes are not a defence against
        // that and never were — they span 600s while RSS sync runs on a ~15 minute
        // interval — so arming them there would buy nothing and risk removing, with
        // removeFromClient: true, a same-target download that simply is not ours.
        if ($blocklistWarning === null) {
            SweepCompetingGrabs::queueFor($mediaReplacementAttempt->id);
        }

        // Finalize a verification a Download webhook recorded WHILE this cleanup
        // was in flight. In that window restoration was deferred to us, so the
        // tracker deliberately left the attempt pending; finalizeAfterCleanup
        // performs the restore and terminalizes it. No-op unless such a pending
        // verification exists, so it never clobbers a webhook terminal outcome.
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
            'competing_grabs_removed' => $competingGrabsRemoved,
        ];
    }

    /**
     * Blocklist the old release when it is safe.
     *
     * Safety rests on ONE thing only: this run suspended monitoring itself, moments
     * ago, and $blocklistAllowed carries that result. Both callers compute it from a
     * fresh unmonitor rather than from durable state, which is what makes the claim
     * checkable here. It is false when a monitored target could not be suspended,
     * where markHistoryFailed would launch the competing auto-search unopposed.
     *
     * Do NOT restate this as a guarantee about other actors. The cleanup phase being
     * open (cleanup_completed_at still null) does keep the webhook tracker from
     * remonitoring, but it does not bind everyone: media-replacement:reconcile
     * restores monitoring on a `downloading` row it times out regardless of the
     * cleanup phase, and on a settled row with no import event at all. Both of its
     * passes are bounded by the --hours cutoff, and because the resume path rewrites
     * started_at to now, both re-evaluate that cutoff against current state rather than
     * against their selecting query: the timeout pass atomically, inside its
     * conditional update, and the repair pass on a fresh read.
     *
     * Neither is closed, and both windows open BEFORE this run's started_at write
     * commits. The timeout pass is excluded only from the moment that write lands — an
     * update of its that commits earlier still flags the row and still runs its own
     * restore afterwards, which is the residual spelled out inline in
     * ReconcileMediaReplacementAttempts::handle(). The repair pass is the same shape: it
     * can decide on a read taken before that write and issue its monitor PUT after it.
     *
     * So do not claim the target is guaranteed unmonitored here. What is true is that
     * this run suspended it itself, with only the file delete in between, and that the
     * only actors in a position to have undone it are those two passes, each acting on
     * a decision it took before this run's started_at write landed.
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

        // Safe regardless of the attempt's status: $blocklistAllowed means this run
        // just suspended monitoring on the target itself.
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

        return $connection;
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
        MediaReplacementAttempt::query()
            ->whereKey($mediaReplacementAttempt->id)
            ->whereNotIn('status', MediaReplacementStatus::terminalValues())
            ->update([
                'status' => $mediaReplacementStatus->value,
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
