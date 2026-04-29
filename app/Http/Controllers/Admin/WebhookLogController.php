<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        $builder = $this->buildBuilder($serviceId, $eventType)
            ->with('serviceConnection:id,name,type');

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
        $webhookEvent->load('serviceConnection:id,name,type');

        return Inertia::render('Admin/WebhookLog/Show', [
            'event' => [
                'id' => $webhookEvent->id,
                'service_name' => $webhookEvent->serviceConnection?->name,
                'service_type' => $webhookEvent->serviceConnection?->type->value,
                'event_type' => $webhookEvent->event_type,
                'created_at' => $webhookEvent->created_at?->toIso8601String(),
                'processed_at' => $webhookEvent->processed_at?->toIso8601String(),
                'payload' => $webhookEvent->payload,
                'payload_hash' => $webhookEvent->payload_hash,
            ],
        ]);
    }

    /**
     * @return Builder<WebhookEvent>
     */
    private function buildBuilder(int $serviceId, string $eventType): Builder
    {
        $builder = WebhookEvent::query()->latest();

        if ($serviceId > 0) {
            $builder->where('service_connection_id', $serviceId);
        }

        if ($eventType !== '') {
            $builder->where('event_type', $eventType);
        }

        return $builder;
    }
}
