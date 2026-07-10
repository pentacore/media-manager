<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\WebhookHandlingStatus;
use App\Http\Controllers\Controller;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Settings\WebhookSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebhookLogController extends Controller
{
    public function index(Request $request, WebhookSettings $webhookSettings): Response
    {
        $serviceId = $request->integer('service_id');
        $eventType = $request->string('event_type')->toString();
        $handlingStatus = $request->string('handling_status')->toString();

        $builder = $this->buildBuilder($serviceId, $eventType, $handlingStatus)
            ->with(['serviceConnection:id,name,type', 'agentDecision:id,webhook_event_id,status,actions_count'])
            ->withCount(['activityLogs', 'actionRequests']);

        $lengthAwarePaginator = $builder->paginate(50)->withQueryString();

        return Inertia::render('Admin/WebhookLog/Index', [
            'events' => [
                'data' => $lengthAwarePaginator->getCollection()->map(fn (WebhookEvent $webhookEvent): array => [
                    'id' => $webhookEvent->id,
                    'service_name' => $webhookEvent->serviceConnection?->name,
                    'service_type' => $webhookEvent->serviceConnection?->type->value,
                    'event_type' => $webhookEvent->event_type,
                    'created_at' => $webhookEvent->created_at?->toIso8601String(),
                    'processed_at' => $webhookEvent->processed_at?->toIso8601String(),
                    'payload_hash' => $webhookEvent->payload_hash,
                    'handling_status' => $webhookEvent->handling_status?->value,
                    'activity_count' => $webhookEvent->activity_logs_count,
                    'action_count' => $webhookEvent->action_requests_count,
                    'agent_decision' => $webhookEvent->agentDecision === null ? null : [
                        'status' => $webhookEvent->agentDecision->status->value,
                        'actions_count' => $webhookEvent->agentDecision->actions_count,
                    ],
                ])->all(),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            'filters' => [
                'service_id' => $serviceId > 0 ? $serviceId : null,
                'event_type' => $eventType,
                'handling_status' => $handlingStatus !== '' ? $handlingStatus : null,
            ],
            'filterOptions' => [
                'services' => ServiceConnection::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'type'])
                    ->map(fn (ServiceConnection $serviceConnection): array => [
                        'id' => $serviceConnection->id,
                        'name' => $serviceConnection->name,
                        'type' => $serviceConnection->type->value,
                    ])
                    ->all(),
                'eventTypes' => WebhookEvent::query()
                    ->select('event_type')
                    ->distinct()
                    ->orderBy('event_type')
                    ->pluck('event_type')
                    ->filter()
                    ->values()
                    ->all(),
                'handlingStatuses' => array_map(
                    fn (WebhookHandlingStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ],
                    WebhookHandlingStatus::cases(),
                ),
            ],
            'settings' => [
                'capture_enabled' => $webhookSettings->captureEnabled(),
            ],
        ]);
    }

    public function updateSettings(Request $request, WebhookSettings $webhookSettings): RedirectResponse
    {
        $validated = $request->validate([
            'capture_enabled' => ['required', 'boolean'],
        ]);

        $webhookSettings->setCaptureEnabled((bool) $validated['capture_enabled']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['capture_enabled']
                ? __('Webhook capture enabled.')
                : __('Webhook capture disabled.'),
        ]);

        return back();
    }

    public function show(WebhookEvent $webhookEvent): Response
    {
        $webhookEvent->load([
            'serviceConnection:id,name,type',
            'activityLogs' => fn ($query) => $query->latest(),
            'agentDecision',
            'actionRequests' => fn ($query) => $query->latest(),
        ]);

        return Inertia::render('Admin/WebhookLog/Show', [
            'event' => [
                'id' => $webhookEvent->id,
                'service_name' => $webhookEvent->serviceConnection?->name,
                'service_type' => $webhookEvent->serviceConnection?->type->value,
                'event_type' => $webhookEvent->event_type,
                'created_at' => $webhookEvent->created_at?->toIso8601String(),
                'processed_at' => $webhookEvent->processed_at?->toIso8601String(),
                'handling_status' => $webhookEvent->handling_status?->value,
                'payload' => $webhookEvent->payload,
                'payload_hash' => $webhookEvent->payload_hash,
                'activity' => $webhookEvent->activityLogs->map(fn (ActivityLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'metadata' => $log->metadata,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])->all(),
                'agent_decision' => $webhookEvent->agentDecision === null ? null : [
                    'status' => $webhookEvent->agentDecision->status->value,
                    'summary' => $webhookEvent->agentDecision->summary,
                    'actions_count' => $webhookEvent->agentDecision->actions_count,
                    'action_request_ids' => $webhookEvent->agentDecision->action_request_ids,
                ],
                'actions' => $webhookEvent->actionRequests->map(fn (ActionRequest $action): array => [
                    'id' => $action->id,
                    'type' => $action->type,
                    'target_service' => $action->target_service,
                    'status' => $action->status->value,
                    'created_at' => $action->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * @return Builder<WebhookEvent>
     */
    private function buildBuilder(int $serviceId, string $eventType, string $handlingStatus): Builder
    {
        $builder = WebhookEvent::query()->latest();

        if ($serviceId > 0) {
            $builder->where('service_connection_id', $serviceId);
        }

        if ($eventType !== '') {
            $builder->where('event_type', $eventType);
        }

        if ($handlingStatus !== '') {
            $builder->where('handling_status', $handlingStatus);
        }

        return $builder;
    }
}
