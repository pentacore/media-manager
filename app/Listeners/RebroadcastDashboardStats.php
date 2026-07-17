<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Support\Facades\Cache;

class RebroadcastDashboardStats
{
    public function __construct(private readonly DashboardStatsService $dashboardStatsService) {}

    /**
     * Triggered by WebhookReceived, ActionRequestCreated,
     * ActionRequestStatusChanged, and ServiceHealthChanged. Re-runs the
     * dashboard stats query and rebroadcasts.
     *
     * Throttled to at most one broadcast per second so a burst of webhooks
     * (e.g. a Sonarr import grabbing 24 episodes at once) doesn't fan out
     * 24 broadcasts.
     */
    public function handle(object $event): void
    {
        $lock = Cache::lock('dashboard-stats-broadcast', 1);

        if (! $lock->get()) {
            // Record that work arrived while a broadcast was in flight — the
            // holder rebroadcasts once more, so the trailing edge of a burst
            // (e.g. the last of 24 imported episodes) is never dropped.
            Cache::put('dashboard-stats-broadcast:dirty', true, 60);

            return;
        }

        try {
            $this->dashboardStatsService->broadcast();

            if (Cache::pull('dashboard-stats-broadcast:dirty') !== null) {
                $this->dashboardStatsService->broadcast();
            }
        } finally {
            // Lock auto-expires after 1s; explicit release is a no-op if the
            // listener finished within the TTL.
        }
    }
}
