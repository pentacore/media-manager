<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActionRequest;
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
    public function __construct(private readonly ActionRequestActivityLogger $actionRequestActivityLogger) {}

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
    }
}
