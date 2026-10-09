<?php

declare(strict_types=1);

namespace App\Observers;

use App\Ai\Classification\ClassificationOutcomeRecorder;
use App\Enums\ActionRequestStatus;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Enums\StuckImportChoice;
use App\Models\ActionRequest;
use App\Models\ClassificationOutcome;
use App\Services\Actions\ActionRequestActivityLogger;
use Exception;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Runs after the caller's transaction commits, so a rollback never leaves an
 * activity row (or its broadcast) for a request or transition that never
 * existed. Every ActionRequest status write is one save per transaction, so
 * wasChanged('status') read at commit time is the change that was made —
 * keep it that way: a second save on the same model instance inside the same
 * transaction (another status change, or any other attribute) overwrites
 * wasChanged() before the deferred event fires, so only the final save is
 * logged and an earlier status change is lost.
 *
 * A failing activity write is reported, never thrown: after-commit callbacks
 * run in order with no isolation, and the job push the caller queued after
 * this one must still run. Before R5 the same failure rolled the request
 * back; now it is committed, so aborting here would leave it Approved with
 * no job until actions:reconcile-stuck picks it up.
 */
class ActionRequestObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ActionRequestActivityLogger $actionRequestActivityLogger,
        private readonly ClassificationOutcomeRecorder $classificationOutcomeRecorder,
    ) {}

    public function created(ActionRequest $actionRequest): void
    {
        try {
            $this->actionRequestActivityLogger->created($actionRequest);
        } catch (Exception $exception) {
            report($exception);
        }
    }

    public function updated(ActionRequest $actionRequest): void
    {
        if (! $actionRequest->wasChanged('status')) {
            return;
        }

        try {
            $this->actionRequestActivityLogger->statusChanged($actionRequest);
        } catch (Exception $exception) {
            report($exception);
        }

        $this->resolveStuckImportOutcome($actionRequest);
    }

    /**
     * A classifier-queued stuck-import action was right when a human approved
     * it or it auto-ran to completion, and wrong when a human rejected it.
     * Filtered to the row the action's own type predicted, so an unrelated
     * action on the same download (e.g. a chat-queued removal while the
     * classifier's "manual" row, or a different choice's row, is still open)
     * never resolves the wrong outcome.
     */
    private function resolveStuckImportOutcome(ActionRequest $actionRequest): void
    {
        if (! in_array($actionRequest->type, ['resolve_manual_import', 'remove_stuck_download'], true)) {
            return;
        }

        $positive = match ($actionRequest->status) {
            ActionRequestStatus::Approved => true,
            ActionRequestStatus::Completed => $actionRequest->requires_approval ? null : true,
            ActionRequestStatus::Rejected => false,
            default => null,
        };

        $service = $actionRequest->payload['service'] ?? null;
        $downloadId = $actionRequest->payload['download_id'] ?? null;

        if ($positive === null || ! is_string($service) || ! is_string($downloadId)) {
            return;
        }

        $predicted = $actionRequest->type === 'resolve_manual_import'
            ? StuckImportChoice::Import->value
            : StuckImportChoice::Remove->value;

        $this->classificationOutcomeRecorder->resolve(
            ClassificationGate::StuckImport,
            ClassificationOutcome::subjectKey('download', sprintf('%s:%s', $service, $downloadId)),
            $positive,
            $actionRequest->status->value,
            [ClassificationVerdict::ResolvedByClassifier],
            predicted: $predicted,
        );
    }
}
