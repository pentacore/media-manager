<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyLibraryScanScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Refresh library" on Now Playing (admin). Joins a scan that is already
 * waiting for the active Emby server, or dispatches emby_library_scan pinned
 * to that server through the Action Queue (origin manual, Action Rules
 * apply) — both under the scheduler's per-server lock.
 */
class LibraryRefreshController extends Controller
{
    public function __invoke(Request $request, EmbyLibraryScanScheduler $embyLibraryScanScheduler): RedirectResponse
    {
        $connection = ServiceConnection::findActive(ServiceType::Emby);

        if (! $connection instanceof ServiceConnection) {
            return $this->noActiveConnectionRedirect(ServiceType::Emby, back());
        }

        $refresh = $embyLibraryScanScheduler->foldOrDispatchManual(
            $connection->id,
            sprintf('Requested from Now Playing by %s.', $request->user()->name),
        );

        if ($refresh instanceof ActionRequest) {
            $this->log($request, $connection, $refresh, folded: true);

            Inertia::flash('toast', $refresh->status === ActionRequestStatus::Pending
                ? ['type' => 'info', 'message' => __('Queued for approval in the Action Queue.')]
                : ['type' => 'success', 'message' => __('Library refresh queued.')]);

            return back();
        }

        if ($refresh->actionRequest instanceof ActionRequest) {
            $this->log($request, $connection, $refresh->actionRequest, folded: false);
        }

        Inertia::flash('toast', $refresh->toast(__('Library refresh queued.')));

        return back();
    }

    private function log(Request $request, ServiceConnection $serviceConnection, ActionRequest $actionRequest, bool $folded): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'service_connection_id' => $serviceConnection->id,
            'action' => 'emby.library_refresh.requested',
            'subject_type' => $actionRequest->getMorphClass(),
            'subject_id' => $actionRequest->id,
            'description' => $folded
                ? 'Asked Emby to refresh its library (joined a refresh that was already waiting).'
                : 'Asked Emby to refresh its library.',
            'metadata' => ['action_request_id' => $actionRequest->id, 'folded' => $folded],
        ]);
    }
}
