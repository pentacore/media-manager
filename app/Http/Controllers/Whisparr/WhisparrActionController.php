<?php

declare(strict_types=1);

namespace App\Http\Controllers\Whisparr;

use App\Enums\LibraryBulkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Whisparr\BulkWhisparrActionRequest;
use App\Http\Requests\Whisparr\DeleteWhisparrItemRequest;
use App\Http\Requests\Whisparr\MonitorWhisparrItemRequest;
use App\Http\Requests\Whisparr\SetWhisparrQualityProfileRequest;
use App\Http\Requests\Whisparr\WhisparrItemRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\BulkItemOutcome;
use App\Services\Actions\BulkRunner;
use App\Services\Actions\ManualActionOutcome;
use App\Services\Library\LibraryActionRequester;
use App\Services\Whisparr\WhisparrClient;
use App\Services\Whisparr\WhisparrItemPresenter;
use App\Services\Whisparr\WhisparrUnexpectedResponse;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
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

    public function bulk(BulkWhisparrActionRequest $bulkWhisparrActionRequest, LibraryActionRequester $libraryActionRequester, BulkRunner $bulkRunner, WhisparrItemPresenter $whisparrItemPresenter): JsonResponse
    {
        $validated = $bulkWhisparrActionRequest->validated();
        $connection = $bulkWhisparrActionRequest->connection();
        $libraryBulkAction = LibraryBulkAction::from((string) $validated['action']);
        $qualityProfileId = isset($validated['quality_profile_id']) ? (int) $validated['quality_profile_id'] : null;
        $deleteFiles = (bool) ($validated['delete_files'] ?? false);
        $because = sprintf('Requested in bulk from the Whisparr library by %s.', $bulkWhisparrActionRequest->user()->name);

        $bulkSummary = $bulkRunner->run(
            $bulkWhisparrActionRequest->bulkIds(),
            fn (int $itemId): BulkItemOutcome => BulkItemOutcome::fromManualAction(
                $libraryActionRequester->apply($libraryBulkAction, $connection, $itemId, $qualityProfileId, $deleteFiles, $because),
            ),
            $this->titleLookup($connection, $whisparrItemPresenter),
        );

        return response()->json($bulkSummary->withToast());
    }

    /**
     * Failure-line names from Whisparr's cached library list (the page just
     * loaded it), fetched once and only when an item failed.
     *
     * @return Closure(int): string
     */
    private function titleLookup(ServiceConnection $serviceConnection, WhisparrItemPresenter $whisparrItemPresenter): Closure
    {
        /** @var array<int, string>|null $titles */
        $titles = null;

        return function (int $itemId) use (&$titles, $serviceConnection, $whisparrItemPresenter): string {
            if ($titles === null) {
                try {
                    $rows = $whisparrItemPresenter->rows($serviceConnection->whisparrVersion(), new WhisparrClient($serviceConnection)->getItems());
                    $titles = array_column($rows, 'title', 'id');
                } catch (RequestException|ConnectionException|WhisparrUnexpectedResponse) {
                    $titles = [];
                }
            }

            return $titles[$itemId] ?? sprintf('#%d', $itemId);
        };
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
