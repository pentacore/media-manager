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
            'filters' => ['status' => $status],
        ]);
    }

    public function approve(Request $request, ActionRequest $actionRequest): RedirectResponse
    {
        if ($actionRequest->status !== ActionRequestStatus::Pending) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only pending requests can be approved.')]);

            return back();
        }

        DB::transaction(function () use ($request, $actionRequest): void {
            $actionRequest->update([
                'status' => ActionRequestStatus::Approved,
                'approved_by' => $request->user()->id,
            ]);
            event(new ActionRequestStatusChanged($actionRequest));
            dispatch(new ExecuteActionRequest($actionRequest))->afterCommit();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Action approved and queued.')]);

        return back();
    }

    public function reject(Request $request, ActionRequest $actionRequest): RedirectResponse
    {
        if ($actionRequest->status !== ActionRequestStatus::Pending) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only pending requests can be rejected.')]);

            return back();
        }

        DB::transaction(function () use ($request, $actionRequest): void {
            $actionRequest->update([
                'status' => ActionRequestStatus::Rejected,
                'approved_by' => $request->user()->id,
            ]);
            event(new ActionRequestStatusChanged($actionRequest));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Action rejected.')]);

        return back();
    }

    public function retry(ActionRequest $actionRequest): RedirectResponse
    {
        if ($actionRequest->status !== ActionRequestStatus::Failed) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only failed requests can be retried.')]);

            return back();
        }

        DB::transaction(function () use ($actionRequest): void {
            $actionRequest->update([
                'status' => ActionRequestStatus::Approved,
                'result' => null,
            ]);
            event(new ActionRequestStatusChanged($actionRequest));
            dispatch(new ExecuteActionRequest($actionRequest))->afterCommit();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Action requeued for execution.')]);

        return back();
    }
}
