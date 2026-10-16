<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActionRequest;

/**
 * Numbers action request status changes (status_version). It runs inside the
 * save, before the INSERT or UPDATE, so the number is written in the same
 * statement as the status it belongs to and the model carries it. Pre-save
 * hooks (`creating`, `updating`, `saving`, …) run synchronously even on a
 * ShouldHandleEventsAfterCommit observer (the event dispatcher exempts them),
 * so this could live on ActionRequestObserver; it is kept as its own class so
 * numbering stays apart from the after-commit audit and broadcast observer.
 * Query-builder updates never reach it; they set status_version themselves
 * through ActionRequest::statusVersionExpression()
 * (ActionRequestStatusVersionArchTest). A bare Event::fake() swaps out the
 * model event dispatcher and turns it off.
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
