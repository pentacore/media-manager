<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActionRequestStatus;
use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Emby\EmbyClient;
use App\Services\ServiceMetrics\ServiceMetricsRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ServiceMetricsRepository $serviceMetricsRepository): Response
    {
        $services = ServiceConnection::query()
            ->orderBy('type')
            ->get();

        $healthyCount = $services->filter(
            fn (ServiceConnection $serviceConnection): bool => $serviceConnection->health_status === HealthStatus::Healthy,
        )->count();

        $pendingActions = ActionRequest::where('status', ActionRequestStatus::Pending)->count();

        return Inertia::render('Dashboard', [
            'stats' => [
                'activeServices' => $services->where('is_active', true)->count(),
                'totalServices' => $services->count(),
                'healthyServices' => $healthyCount,
                'recentWebhooks' => WebhookEvent::where('created_at', '>=', now()->subDay())->count(),
                'pendingActions' => $pendingActions,
                'failedActions' => ActionRequest::where('status', ActionRequestStatus::Failed)
                    ->where('created_at', '>=', now()->subDay())->count(),
                'recentActions' => ActionRequest::where('created_at', '>=', now()->subDay())->count(),
            ],
            'services' => $services->map(fn (ServiceConnection $serviceConnection): array => [
                'id' => $serviceConnection->id,
                'type' => $serviceConnection->type->value,
                'name' => $serviceConnection->name,
                'health' => $serviceConnection->health_status?->value ?? 'unknown',
                'version' => $serviceConnection->version,
                'latest_version' => $serviceConnection->latest_version,
                'last_seen_at' => $serviceConnection->last_seen_at?->toISOString(),
                'is_active' => $serviceConnection->is_active,
                'latency_spark' => $serviceMetricsRepository->recentLatencySamples($serviceConnection->id),
                'avg_latency_ms' => $serviceMetricsRepository->averageLatencyMs($serviceConnection->id),
            ])->values(),
            'recentActivity' => ActivityLogResource::collection(
                ActivityLog::with(['user:id,name', 'serviceConnection:id,name,type'])
                    ->latest()
                    ->take(10)
                    ->get()
            )->toArray($request),
            'recentWebhookEvents' => WebhookEvent::with('serviceConnection:id,name,type')
                ->latest()
                ->take(5)
                ->get()
                ->map(fn (WebhookEvent $webhookEvent): array => [
                    'id' => $webhookEvent->id,
                    'event_type' => $webhookEvent->event_type,
                    'service_name' => $webhookEvent->serviceConnection?->name,
                    'service_type' => $webhookEvent->serviceConnection?->type->value,
                    'processed' => $webhookEvent->processed_at !== null,
                    'created_at' => $webhookEvent->created_at?->toISOString(),
                ]),
            'pendingApprovals' => ActionRequest::with([
                'webhookEvent.serviceConnection:id,name,type',
                'approvedByUser:id,name',
            ])
                ->where('status', ActionRequestStatus::Pending)
                ->latest()
                ->take(3)
                ->get()
                ->map(fn (ActionRequest $actionRequest): array => [
                    'id' => $actionRequest->id,
                    'type' => $actionRequest->type,
                    'target_service' => $actionRequest->target_service,
                    'subject_label' => is_string($actionRequest->payload['title'] ?? null)
                        ? $actionRequest->payload['title']
                        : ($actionRequest->payload['name'] ?? '—'),
                    'requested_by' => $actionRequest->approvedByUser?->name
                        ?? $actionRequest->webhookEvent?->serviceConnection?->name
                        ?? 'system',
                    'trigger' => $actionRequest->webhookEvent?->event_type
                        ?? 'manual',
                    'created_at' => $actionRequest->created_at?->toISOString(),
                ]),
            'nowPlaying' => Inertia::defer(fn (): array => $this->loadNowPlaying()),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadNowPlaying(): array
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            return [];
        }

        try {
            $sessions = new EmbyClient($connection)->getActiveSessions();
        } catch (RequestException|ConnectionException) {
            return [];
        }

        return array_values(array_map(
            fn (array $session): array => [
                'media_title' => $session['NowPlayingItem']['Name'] ?? null,
                'series_title' => $session['NowPlayingItem']['SeriesName'] ?? null,
                'emby_username' => $session['UserName'] ?? null,
                'media_type' => strtolower((string) ($session['NowPlayingItem']['Type'] ?? '')),
                'action' => ($session['PlayState']['IsPaused'] ?? false) ? 'paused' : 'playing',
                'play_position' => $session['PlayState']['PositionTicks'] ?? null,
                'duration_ticks' => $session['NowPlayingItem']['RunTimeTicks'] ?? null,
            ],
            array_filter($sessions, static fn (array $session): bool => isset($session['NowPlayingItem'])),
        ));
    }
}
