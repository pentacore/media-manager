<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActionRequest;

/**
 * Numbers action request status changes (status_version). It runs inside the
 * save, before the INSERT or UPDATE, so the number is written in the same
 * statement as the status it belongs to and the model carries it. That is why
 * it is separate from ActionRequestObserver and does not implement
 * ShouldHandleEventsAfterCommit: that interface defers every method,
 * `updating` included, until after the save. Query-builder updates never
 * reach it; they set status_version themselves
 * (ActionRequestStatusVersionArchTest).
 */
final class ActionRequestStatusVersionObserver
{
    public function creating(ActionRequest $actionRequest): void
    {
        $actionRequest->status_version = ActionRequest::nextStatusVersion();
    }

    public function updating(ActionRequest $actionRequest): void
    {
        if ($actionRequest->isDirty('status')) {
            $actionRequest->status_version = ActionRequest::nextStatusVersion();
        }
    }
}
