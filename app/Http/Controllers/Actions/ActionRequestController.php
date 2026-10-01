<?php

declare(strict_types=1);

namespace App\Http\Controllers\Actions;

use App\Enums\ActionQueueBulkAction;
use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Actions\BulkReviewActionRequestsRequest;
use App\Http\Resources\ActionRequestResource;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Services\Actions\ActionRequestReviewer;
use App\Services\Actions\BulkItemOutcome;
use App\Services\Actions\BulkRunner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ActionRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $preselect = $request->integer('request');

        $builder = ActionRequest::with([
            'webhookEvent.serviceConnection:id,name,type',
            'approvedByUser:id,name',
            'mediaReplacementAttempt:id,action_request_id,status,failure_reason',
        ])->latest();

        if ($status !== '') {
            $builder->where('status', $status);
        }

        $lengthAwarePaginator = $builder->paginate(25)->withQueryString();

        return Inertia::render('Actions/Index', [
            'requests' => [
                'data' => ActionRequestResource::collection($lengthAwarePaginator->getCollection())->toArray($request),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            // Per-status totals for the tab strip — page-paginated rows
            // can't drive these accurately, especially when the user is
            // already filtered to a single status. Refreshed via partial
            // Inertia reload on each ActionRequestStatusChanged broadcast.
            'statusCounts' => $this->statusCounts(),
            'filters' => ['status' => $status],
            'preselect' => $preselect > 0 ? $preselect : null,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = ActionRequest::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $out = [];
        foreach (ActionRequestStatus::cases() as $case) {
            $out[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return $out;
    }

    public function approve(Request $request, ActionRequest $actionRequest, ActionRequestReviewer $actionRequestReviewer): RedirectResponse
    {
        if (! $actionRequestReviewer->approve($actionRequest, $request->user())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only pending requests can be approved.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Action approved and queued.')]);

        return back();
    }

    public function reject(Request $request, ActionRequest $actionRequest, ActionRequestReviewer $actionRequestReviewer): RedirectResponse
    {
        if (! $actionRequestReviewer->reject($actionRequest, $request->user())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only pending requests can be rejected.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Action rejected.')]);

        return back();
    }

    public function bulk(BulkReviewActionRequestsRequest $bulkReviewActionRequestsRequest, ActionRequestReviewer $actionRequestReviewer, BulkRunner $bulkRunner): JsonResponse
    {
        $validated = $bulkReviewActionRequestsRequest->validated();
        $actionQueueBulkAction = ActionQueueBulkAction::from((string) $validated['action']);
        $reason = is_string($validated['reason'] ?? null) && Str::trim($validated['reason']) !== '' ? Str::trim($validated['reason']) : null;
        $ids = $bulkReviewActionRequestsRequest->bulkIds();
        $user = $bulkReviewActionRequestsRequest->user();
        $actionRequests = ActionRequest::query()->whereKey($ids)->get()->keyBy('id');

        $bulkSummary = $bulkRunner->run(
            $ids,
            function (int $id) use ($actionRequests, $actionQueueBulkAction, $actionRequestReviewer, $user, $reason): BulkItemOutcome {
                $actionRequest = $actionRequests->get($id);

                if (! $actionRequest instanceof ActionRequest) {
                    return BulkItemOutcome::failed(__('That request no longer exists.'));
                }

                try {
                    $reviewed = $actionQueueBulkAction === ActionQueueBulkAction::Approve
                        ? $actionRequestReviewer->approve($actionRequest, $user)
                        : $actionRequestReviewer->reject($actionRequest, $user, $reason);
                } catch (ModelNotFoundException) {
                    return BulkItemOutcome::failed(__('That request no longer exists.'));
                }

                // Reviewed elsewhere since the page loaded: counted, not an error.
                return $reviewed ? BulkItemOutcome::started() : BulkItemOutcome::skipped();
            },
            function (int $id) use ($actionRequests): string {
                // Not a plain ?->title: Larastan misreads ActionRequest|null
                // from Collection::get() as never-null in this position and
                // flags the nullsafe operator as redundant (already
                // baselined elsewhere for this model) — an explicit null
                // check avoids adding another baseline entry.
                $actionRequest = $actionRequests->get($id);

                return $actionRequest === null ? sprintf('#%d', $id) : ($actionRequest->title ?? sprintf('#%d', $id));
            },
        );

        return response()->json($bulkSummary->withToast($actionQueueBulkAction->pastTense()));
    }

    public function retry(ActionRequest $actionRequest): RedirectResponse
    {
        $retried = DB::transaction(function () use ($actionRequest): bool {
            $lockedActionRequest = ActionRequest::query()
                ->whereKey($actionRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedActionRequest->status !== ActionRequestStatus::Failed) {
                return false;
            }

            $lockedActionRequest->update([
                'status' => ActionRequestStatus::Approved,
                'result' => null,
            ]);
            // Broadcast only after the transaction commits: firing inside it
            // announced state that could still roll back, and a fast client
            // partial-reload could read pre-commit data.
            DB::afterCommit(static fn () => event(new ActionRequestStatusChanged($lockedActionRequest)));
            dispatch(new ExecuteActionRequest($lockedActionRequest))->afterCommit();

            return true;
        });

        if (! $retried) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only failed requests can be retried.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Action requeued for execution.')]);

        return back();
    }
}
