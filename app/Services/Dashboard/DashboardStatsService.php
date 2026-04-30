<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\ActionRequestStatus;
use App\Enums\HealthStatus;
use App\Events\DashboardStatsUpdated;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;

class DashboardStatsService
{
    /**
     * @return array{activeServices: int, totalServices: int, healthyServices: int, recentWebhooks: int, pendingActions: int, recentActions: int, failedActions: int}
     */
    public function snapshot(): array
    {
        $since = now()->subDay();

        return [
            'activeServices' => ServiceConnection::where('is_active', true)->count(),
            'totalServices' => ServiceConnection::count(),
            'healthyServices' => ServiceConnection::where('health_status', HealthStatus::Healthy)->count(),
            'recentWebhooks' => WebhookEvent::where('created_at', '>=', $since)->count(),
            'pendingActions' => ActionRequest::where('status', ActionRequestStatus::Pending)->count(),
            'recentActions' => ActionRequest::where('created_at', '>=', $since)->count(),
            'failedActions' => ActionRequest::where('status', ActionRequestStatus::Failed)
                ->where('created_at', '>=', $since)->count(),
        ];
    }

    public function broadcast(): DashboardStatsUpdated
    {
        $snapshot = $this->snapshot();

        $dashboardStatsUpdated = new DashboardStatsUpdated(
            activeServices: $snapshot['activeServices'],
            totalServices: $snapshot['totalServices'],
            healthyServices: $snapshot['healthyServices'],
            recentWebhooks: $snapshot['recentWebhooks'],
            pendingActions: $snapshot['pendingActions'],
            recentActions: $snapshot['recentActions'],
            failedActions: $snapshot['failedActions'],
        );

        event($dashboardStatsUpdated);

        return $dashboardStatsUpdated;
    }
}
