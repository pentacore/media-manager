<?php

declare(strict_types=1);

namespace App\Http\Controllers\Whisparr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Whisparr\DeleteWhisparrItemRequest;
use App\Http\Requests\Whisparr\MonitorWhisparrItemRequest;
use App\Http\Requests\Whisparr\SetWhisparrQualityProfileRequest;
use App\Http\Requests\Whisparr\WhisparrItemRequest;
use App\Services\Actions\ManualActionOutcome;
use App\Services\Library\LibraryActionRequester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin Whisparr actions from the title page. Every write goes through
 * LibraryActionRequester (Action Queue, Action Rules, audit) and pins the
 * connection the page was rendered from.
 */
class WhisparrActionController extends Controller
{
    public function monitor(MonitorWhisparrItemRequest $monitorWhisparrItemRequest, LibraryActionRequester $libraryActionRequester): RedirectResponse
    {
        $validated = $monitorWhisparrItemRequest->validated();

        return $this->answer($libraryActionRequester->monitor(
            $monitorWhisparrItemRequest->connection(),
            (int) $validated['item_id'],
            (bool) $validated['monitored'],
            $this->because($monitorWhisparrItemRequest),
        ), __('Monitoring updated.'));
    }

    public function qualityProfile(SetWhisparrQualityProfileRequest $setWhisparrQualityProfileRequest, LibraryActionRequester $libraryActionRequester): RedirectResponse
    {
        $validated = $setWhisparrQualityProfileRequest->validated();

        return $this->answer($libraryActionRequester->setQualityProfile(
            $setWhisparrQualityProfileRequest->connection(),
            (int) $validated['item_id'],
            (int) $validated['quality_profile_id'],
            $this->because($setWhisparrQualityProfileRequest),
        ), __('Quality profile updated.'));
    }

    public function search(WhisparrItemRequest $whisparrItemRequest, LibraryActionRequester $libraryActionRequester): RedirectResponse
    {
        $validated = $whisparrItemRequest->validated();

        return $this->answer($libraryActionRequester->search(
            $whisparrItemRequest->connection(),
            (int) $validated['item_id'],
            $this->because($whisparrItemRequest),
        ), __('Search started.'));
    }

    public function delete(DeleteWhisparrItemRequest $deleteWhisparrItemRequest, LibraryActionRequester $libraryActionRequester): RedirectResponse
    {
        $validated = $deleteWhisparrItemRequest->validated();

        $manualActionOutcome = $libraryActionRequester->delete(
            $deleteWhisparrItemRequest->connection(),
            (int) $validated['item_id'],
            (bool) ($validated['delete_files'] ?? false),
            $this->because($deleteWhisparrItemRequest),
        );

        Inertia::flash('toast', $manualActionOutcome->toast(__('Deletion started.')));

        return $manualActionOutcome->state === ManualActionOutcome::STARTED
            ? to_route('media.whisparr.index')
            : back();
    }

    private function because(Request $request): string
    {
        return sprintf('Requested from the Whisparr page by %s.', $request->user()->name);
    }

    private function answer(ManualActionOutcome $manualActionOutcome, string $startedMessage): RedirectResponse
    {
        Inertia::flash('toast', $manualActionOutcome->toast($startedMessage));

        return back();
    }
}
