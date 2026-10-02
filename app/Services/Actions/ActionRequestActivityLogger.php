<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;

/**
 * Writes the ActionRequest audit trail. The observer covers Eloquent saves
 * (after commit); conditional query-builder transitions (the Executing claim,
 * the stuck reconcile) bypass observers and call statusChanged() themselves.
 */
final class ActionRequestActivityLogger
{
    public function created(ActionRequest $actionRequest): void
    {
        $this->writeLog($actionRequest, 'action_request.created', sprintf(
            '%s → %s: %s (%s)',
            $actionRequest->source_service,
            $actionRequest->target_service,
            $actionRequest->type,
            $actionRequest->requires_approval ? 'requires approval' : 'auto-execute',
        ));
    }

    public function statusChanged(ActionRequest $actionRequest): void
    {
        match ($actionRequest->status) {
            ActionRequestStatus::Approved => $this->logApproved($actionRequest),
            ActionRequestStatus::Rejected => $this->logRejected($actionRequest),
            ActionRequestStatus::Executing => $this->logExecuting($actionRequest),
            ActionRequestStatus::Completed => $this->logCompleted($actionRequest),
            ActionRequestStatus::Failed => $this->logFailed($actionRequest),
            ActionRequestStatus::Pending => null,
        };
    }

    private function logApproved(ActionRequest $actionRequest): void
    {
        $approver = $actionRequest->approvedByUser?->name;
        $this->writeLog(
            $actionRequest,
            'action_request.approved',
            $approver !== null
                ? sprintf('Action #%d approved by %s', $actionRequest->id, $approver)
                : sprintf('Action #%d approved', $actionRequest->id),
        );
    }

    private function logRejected(ActionRequest $actionRequest): void
    {
        $approver = $actionRequest->approvedByUser?->name;
        $reason = $actionRequest->result['rejection_reason'] ?? null;
        $description = $approver !== null
            ? sprintf('Action #%d rejected by %s', $actionRequest->id, $approver)
            : sprintf('Action #%d rejected', $actionRequest->id);

        $this->writeLog(
            $actionRequest,
            'action_request.rejected',
            is_string($reason) && $reason !== '' ? sprintf('%s: %s', $description, $reason) : $description,
        );
    }

    private function logExecuting(ActionRequest $actionRequest): void
    {
        $this->writeLog($actionRequest, 'action_request.executing', sprintf('Action #%d started', $actionRequest->id));
    }

    private function logCompleted(ActionRequest $actionRequest): void
    {
        $this->writeLog($actionRequest, 'action_request.completed', sprintf('Action #%d completed', $actionRequest->id));
    }

    private function logFailed(ActionRequest $actionRequest): void
    {
        // Only surface short slug-style reasons (e.g. "execution_failed",
        // "retries_exhausted"). Do NOT fall back to the raw exception message,
        // which can contain sensitive server-internal detail.
        $reason = $actionRequest->result['reason'] ?? 'unknown reason';
        $this->writeLog(
            $actionRequest,
            'action_request.failed',
            sprintf('Action #%d failed: %s', $actionRequest->id, $reason),
        );
    }

    private function writeLog(ActionRequest $actionRequest, string $action, string $description): void
    {
        $actionRequest->loadMissing('webhookEvent');

        // Strip sensitive fields from the stored result payload (exception
        // messages, stack traces). Full context stays on the ActionRequest
        // itself; this metadata flows to the Recent Activity feed which is
        // visible to members.
        $result = $actionRequest->result ?? [];
        $safeResult = [
            'success' => $result['success'] ?? null,
            'reason' => $result['reason'] ?? null,
        ];

        ActivityLog::create([
            'user_id' => $actionRequest->approved_by,
            'service_connection_id' => $actionRequest->webhookEvent?->service_connection_id,
            'action' => $action,
            'subject_type' => ActionRequest::class,
            'subject_id' => $actionRequest->id,
            // ActivityLog cuts description to its varchar(255); the full
            // reject reason stays on result.rejection_reason.
            'description' => $description,
            'metadata' => [
                'type' => $actionRequest->type,
                'source_service' => $actionRequest->source_service,
                'target_service' => $actionRequest->target_service,
                'status' => $actionRequest->status->value,
                'result' => $safeResult,
            ],
        ]);
    }
}
