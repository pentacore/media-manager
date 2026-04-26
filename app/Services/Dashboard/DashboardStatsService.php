<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\ActionRequestStatus;
use App\Events\DashboardStatsUpdated;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;

class DashboardStatsService
{
    /**
     * @return array{activeServices: int, totalServices: int, recentWebhooks: int, pendingActions: int}
     */
    public function snapshot(): array
    {
        return [
            'activeServices' => ServiceConnection::where('is_active', true)->count(),
            'totalServices' => ServiceConnection::count(),
            'recentWebhooks' => WebhookEvent::where('created_at', '>=', now()->subDay())->count(),
            'pendingActions' => ActionRequest::where('status', ActionRequestStatus::Pending)->count(),
        ];
    }

    public function broadcast(): DashboardStatsUpdated
    {
        $snapshot = $this->snapshot();

        $dashboardStatsUpdated = new DashboardStatsUpdated(
            activeServices: $snapshot['activeServices'],
            totalServices: $snapshot['totalServices'],
            recentWebhooks: $snapshot['recentWebhooks'],
            pendingActions: $snapshot['pendingActions'],
        );

        event($dashboardStatsUpdated);

        return $dashboardStatsUpdated;
    }
}
