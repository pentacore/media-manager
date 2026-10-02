<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\SubtitleCaseAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands Approved requests whose execution job was lost (a flushed queue, a
 * worker that died before reserving it) back to the queue. Re-dispatching is
 * idempotent: ExecuteActionRequest is ShouldBeUnique, so a job that is in
 * fact still queued keeps its lock and this dispatch is dropped, and the
 * Approved→Executing claim lets exactly one delivery run.
 *
 * A row this old (> MAX_AGE_HOURS) is no longer re-dispatched blind: whatever
 * approved it is long gone, and an admin should look before the upstream
 * effect lands unreviewed. It is failed as needs_reconciliation / never_started
 * instead, the same way a lost worker on an Executing row is.
 *
 * Recovery is bounded by the unique lock's UniqueFor TTL as well as by the
 * cutoff: a lost job whose cache lock survived keeps this dispatch out until
 * that lock expires.
 */
final readonly class StaleApprovedRequestRedispatcher
{
    private const int MAX_AGE_HOURS = 24;

    private const string NEVER_STARTED_MESSAGE = 'This request was approved more than 24 hours ago and its execution job never started. Review it before re-approving by hand.';

    public function __construct(
        private ActionRequestActivityLogger $actionRequestActivityLogger,
        private Dispatcher $busDispatcher,
    ) {}

    public function redispatch(CarbonImmutable $cutoff): StaleApprovedReconciliation
    {
        $ageBound = CarbonImmutable::now()->subHours(self::MAX_AGE_HOURS);

        $stale = ActionRequest::query()
            ->where('status', ActionRequestStatus::Approved->value)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        $finalizedAdvisorRequestIds = $this->finalizedAdvisorRequestIds($stale->all());

        $redispatched = 0;
        $neverStarted = 0;

        foreach ($stale as $actionRequest) {
            if ($this->debounceWindowOpen($actionRequest, $cutoff)) {
                continue;
            }

            if ($actionRequest->updated_at !== null && $actionRequest->updated_at->lessThan($ageBound)) {
                if ($this->failAsNeverStarted($actionRequest, $ageBound)) {
                    $neverStarted++;
                }

                continue;
            }

            if ($this->awaitsAdvisorFinalization($actionRequest, $finalizedAdvisorRequestIds)) {
                continue;
            }

            if ($this->dispatchIfUnclaimed($actionRequest)) {
                $redispatched++;
            }
        }

        return new StaleApprovedReconciliation(redispatched: $redispatched, neverStarted: $neverStarted);
    }

    /**
     * Acquires ExecuteActionRequest's own ShouldBeUnique lock ourselves
     * before dispatching, instead of going through the dispatch() helper.
     * dispatch() re-checks the lock itself but only inside PendingDispatch's
     * destructor, which swallows the outcome — a job that is in fact still
     * queued (holding the lock) would otherwise be silently dropped while
     * this method still logged and counted it as re-dispatched. Acquiring
     * first lets us log and count only a dispatch that actually reaches the
     * queue; the worker still releases this same lock on completion since
     * acquire() stamps the job with its lock owner before it is pushed.
     *
     * The stale set is read once, so a backlogged original job may have
     * claimed and finished the row (releasing its lock) before this loop
     * reaches it; the status is re-read under the lock for that reason. A
     * push that fails releases the lock again and is reported, so one queue
     * blip neither holds the row for the lock's TTL nor aborts the run.
     */
    private function dispatchIfUnclaimed(ActionRequest $actionRequest): bool
    {
        $executeActionRequest = new ExecuteActionRequest($actionRequest);
        $uniqueLock = new UniqueLock(Cache::store());

        if (! $uniqueLock->acquire($executeActionRequest)) {
            return false;
        }

        $stillApproved = ActionRequest::query()
            ->whereKey($actionRequest->id)
            ->where('status', ActionRequestStatus::Approved->value)
            ->exists();

        if (! $stillApproved) {
            $uniqueLock->release($executeActionRequest);

            return false;
        }

        try {
            $this->busDispatcher->dispatch($executeActionRequest);
        } catch (Throwable $throwable) {
            $uniqueLock->release($executeActionRequest);
            report($throwable);

            return false;
        }

        $this->actionRequestActivityLogger->redispatched($actionRequest);

        Log::warning('actions:reconcile-stuck re-dispatched an approved action request that never started', [
            'action_request_id' => $actionRequest->id,
            'type' => $actionRequest->type,
            'approved_since' => $actionRequest->updated_at?->toIso8601String(),
        ]);

        return true;
    }

    /**
     * A coalesced Emby scan waits for scan_after; its ExecuteDebouncedLibraryScan
     * wake-up owns the start until that moment is older than the cutoff.
     */
    private function debounceWindowOpen(ActionRequest $actionRequest, CarbonImmutable $cutoff): bool
    {
        $scanAfter = $actionRequest->payload['scan_after'] ?? null;

        return is_string($scanAfter) && CarbonImmutable::parse($scanAfter)->greaterThan($cutoff);
    }

    /**
     * The subtitle advisor files its replacement Approved but deferred; only
     * RunSubtitleAdvisor::finishWithQueuedAction() starts it, and that marks
     * the advisor attempt for the request Succeeded. Without such an attempt
     * the replacement was deliberately never started. That wait is bounded by
     * the 24 h age bound like any other row: past it, nothing will ever
     * finalize the request, so it fails as never_started.
     *
     * @param  array<int, bool>  $finalizedAdvisorRequestIds
     */
    private function awaitsAdvisorFinalization(ActionRequest $actionRequest, array $finalizedAdvisorRequestIds): bool
    {
        return $actionRequest->source_service === 'subtitle_advisor'
            && ! isset($finalizedAdvisorRequestIds[$actionRequest->id]);
    }

    /**
     * One query for every advisor row in the stale set, keyed by request id.
     *
     * @param  array<int, ActionRequest>  $stale
     * @return array<int, bool>
     */
    private function finalizedAdvisorRequestIds(array $stale): array
    {
        $advisorRequestIds = array_map(
            static fn (ActionRequest $actionRequest): int => $actionRequest->id,
            array_values(array_filter(
                $stale,
                static fn (ActionRequest $actionRequest): bool => $actionRequest->source_service === 'subtitle_advisor',
            )),
        );

        if ($advisorRequestIds === []) {
            return [];
        }

        return SubtitleCaseAttempt::query()
            ->whereIn('action_request_id', $advisorRequestIds)
            ->where('type', SubtitleCaseAttemptType::Advisor)
            ->where('outcome', SubtitleCaseAttemptOutcome::Succeeded)
            ->pluck('action_request_id')
            ->mapWithKeys(static fn (int $actionRequestId): array => [$actionRequestId => true])
            ->all();
    }

    /**
     * A row this stale never reached an executor at all — unlike a lost
     * worker on an Executing row (Task 5), the upstream call never happened,
     * so unlike worker_lost this is NOT indeterminate: there is nothing to
     * check upstream, only who approved it and why nothing ever picked it up.
     */
    private function failAsNeverStarted(ActionRequest $actionRequest, CarbonImmutable $ageBound): bool
    {
        $affected = ActionRequest::query()
            ->whereKey($actionRequest->id)
            ->where('status', ActionRequestStatus::Approved->value)
            ->where('updated_at', '<', $ageBound)
            ->update([
                'status' => ActionRequestStatus::Failed->value,
                'result' => json_encode([
                    'success' => false,
                    'reason' => 'needs_reconciliation',
                    'message' => self::NEVER_STARTED_MESSAGE,
                    'indeterminate' => false,
                    'never_started' => true,
                ]),
            ]);

        if ($affected !== 1) {
            return false;
        }

        $actionRequest->refresh();
        // The conditional update bypasses ActionRequestObserver.
        $this->actionRequestActivityLogger->statusChanged($actionRequest);
        event(new ActionRequestStatusChanged($actionRequest));

        return true;
    }
}
