<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wake-up for a coalesced Emby library scan. EmbyLibraryScanScheduler
 * dispatches one per trigger, delayed to that trigger's scan_after; only the
 * wake-up of the burst's last trigger finds the quiet window over and hands
 * the request to ExecuteActionRequest. Earlier ones are no-ops, so nothing
 * re-dispatches itself.
 */
final class ExecuteDebouncedLibraryScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $actionRequestId) {}

    public function handle(): void
    {
        $actionRequest = ActionRequest::query()->find($this->actionRequestId);

        if (! $actionRequest instanceof ActionRequest || $actionRequest->status !== ActionRequestStatus::Approved) {
            return;
        }

        $scanAfter = $actionRequest->payload['scan_after'] ?? null;

        // A one-second tolerance absorbs the delayed job's second-granular
        // availability timestamp.
        if (is_string($scanAfter) && CarbonImmutable::parse($scanAfter)->greaterThan(now()->addSecond())) {
            return;
        }

        dispatch(new ExecuteActionRequest($actionRequest));
    }
}
