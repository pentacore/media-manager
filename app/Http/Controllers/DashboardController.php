<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Emby\EmbyClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'activeServices' => ServiceConnection::where('is_active', true)->count(),
                'totalServices' => ServiceConnection::count(),
                'recentWebhooks' => WebhookEvent::where('created_at', '>=', now()->subDay())->count(),
                'pendingActions' => ActionRequest::where('status', ActionRequestStatus::Pending)->count(),
            ],
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
