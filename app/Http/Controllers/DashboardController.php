<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
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
            'recentActivity' => ActivityLog::with(['user:id,name', 'serviceConnection:id,name,type'])
                ->latest()
                ->take(10)
                ->get()
                ->map(fn (ActivityLog $activityLog): array => [
                    'id' => $activityLog->id,
                    'action' => $activityLog->action,
                    'description' => $activityLog->description,
                    'user_name' => $activityLog->user?->name,
                    'service_name' => $activityLog->serviceConnection?->name,
                    'service_type' => $activityLog->serviceConnection?->type->value,
                    'created_at' => $activityLog->created_at?->toISOString(),
                ]),
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
            'nowPlaying' => Inertia::optional(fn (): array => EmbyActivity::with('embyUserLink:id,emby_username')
                ->latest()
                ->take(5)
                ->get()
                ->map(fn (EmbyActivity $embyActivity): array => [
                    'id' => $embyActivity->id,
                    'media_type' => $embyActivity->media_type,
                    'media_title' => $embyActivity->media_title,
                    'series_title' => $embyActivity->series_title,
                    'action' => $embyActivity->action,
                    'emby_username' => $embyActivity->embyUserLink?->emby_username,
                ])
                ->all()),
        ]);
    }
}
