<?php

declare(strict_types=1);

namespace App\Http\Controllers\Actions;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionRequestResource;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ActionRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $builder = ActionRequest::with([
            'webhookEvent.serviceConnection:id,name,type',
            'approvedByUser:id,name',
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

    public function approve(Request $request, ActionRequest $actionRequest): RedirectResponse
    {
        $approved = DB::transaction(function () use ($request, $actionRequest): bool {
            $lockedActionRequest = ActionRequest::query()
                ->whereKey($actionRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedActionRequest->status !== ActionRequestStatus::Pending) {
                return false;
            }

            $lockedActionRequest->update([
                'status' => ActionRequestStatus::Approved,
                'approved_by' => $request->user()->id,
            ]);
            // Broadcast only after the transaction commits: firing inside it
            // announced state that could still roll back, and a fast client
            // partial-reload could read pre-commit data.
            DB::afterCommit(static fn () => event(new ActionRequestStatusChanged($lockedActionRequest)));
            dispatch(new ExecuteActionRequest($lockedActionRequest))->afterCommit();

            return true;
        });

        if (! $approved) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only pending requests can be approved.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Action approved and queued.')]);

        return back();
    }

    public function reject(Request $request, ActionRequest $actionRequest): RedirectResponse
    {
        $rejected = DB::transaction(function () use ($request, $actionRequest): bool {
            $lockedActionRequest = ActionRequest::query()
                ->whereKey($actionRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedActionRequest->status !== ActionRequestStatus::Pending) {
                return false;
            }

            $lockedActionRequest->update([
                'status' => ActionRequestStatus::Rejected,
                'approved_by' => $request->user()->id,
            ]);
            // Broadcast only after the transaction commits: firing inside it
            // announced state that could still roll back, and a fast client
            // partial-reload could read pre-commit data.
            DB::afterCommit(static fn () => event(new ActionRequestStatusChanged($lockedActionRequest)));

            return true;
        });

        if (! $rejected) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only pending requests can be rejected.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Action rejected.')]);

        return back();
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
