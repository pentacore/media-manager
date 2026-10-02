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
use Illuminate\Support\Facades\Log;

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
 */
final readonly class StaleApprovedRequestRedispatcher
{
    private const int MAX_AGE_HOURS = 24;

    private const string NEVER_STARTED_MESSAGE = 'This request was approved more than 24 hours ago and its execution job never started. Review it before re-approving by hand.';

    public function __construct(private ActionRequestActivityLogger $actionRequestActivityLogger) {}

    /**
     * @return int how many requests were handed to dispatch() again (one whose job is still queued counts, though its unique lock drops the duplicate)
     */
    public function redispatch(CarbonImmutable $cutoff): int
    {
        $ageBound = CarbonImmutable::now()->subHours(self::MAX_AGE_HOURS);

        $stale = ActionRequest::query()
            ->where('status', ActionRequestStatus::Approved->value)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        $redispatched = 0;

        foreach ($stale as $actionRequest) {
            if ($this->debounceWindowOpen($actionRequest, $cutoff) || $this->awaitsAdvisorFinalization($actionRequest)) {
                continue;
            }

            if ($actionRequest->updated_at !== null && $actionRequest->updated_at->lessThan($ageBound)) {
                $this->failAsNeverStarted($actionRequest);

                continue;
            }

            dispatch(new ExecuteActionRequest($actionRequest));
            $redispatched++;

            $this->actionRequestActivityLogger->redispatched($actionRequest);

            Log::warning('actions:reconcile-stuck re-dispatched an approved action request that never started', [
                'action_request_id' => $actionRequest->id,
                'type' => $actionRequest->type,
                'approved_since' => $actionRequest->updated_at?->toIso8601String(),
            ]);
        }

        return $redispatched;
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
     * the replacement was deliberately never started.
     */
    private function awaitsAdvisorFinalization(ActionRequest $actionRequest): bool
    {
        if ($actionRequest->source_service !== 'subtitle_advisor') {
            return false;
        }

        return ! SubtitleCaseAttempt::query()
            ->where('action_request_id', $actionRequest->id)
            ->where('type', SubtitleCaseAttemptType::Advisor)
            ->where('outcome', SubtitleCaseAttemptOutcome::Succeeded)
            ->exists();
    }

    /**
     * A row this stale is treated like a lost worker on an Executing row
     * (Task 5): same reason, same indeterminate marker, except the marker
     * records that execution never even started.
     */
    private function failAsNeverStarted(ActionRequest $actionRequest): void
    {
        $affected = ActionRequest::query()
            ->whereKey($actionRequest->id)
            ->where('status', ActionRequestStatus::Approved->value)
            ->update([
                'status' => ActionRequestStatus::Failed->value,
                'result' => json_encode([
                    'success' => false,
                    'reason' => 'needs_reconciliation',
                    'message' => self::NEVER_STARTED_MESSAGE,
                    'indeterminate' => true,
                    'never_started' => true,
                ]),
            ]);

        if ($affected !== 1) {
            return;
        }

        $actionRequest->refresh();
        // The conditional update bypasses ActionRequestObserver.
        $this->actionRequestActivityLogger->statusChanged($actionRequest);
        event(new ActionRequestStatusChanged($actionRequest));
    }
}
