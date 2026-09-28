<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\BroadcastDashboardStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RebroadcastDashboardStats
{
    /**
     * Triggered by WebhookReceived, ActionRequestCreated,
     * ActionRequestStatusChanged, and ServiceHealthChanged. Schedules one
     * delayed BroadcastDashboardStats per burst instead of running the seven
     * COUNT queries on the webhook request. Deferred past commit so a pending
     * job can never snapshot before this event's write is visible.
     */
    public function handle(object $event): void
    {
        DB::afterCommit(static function (): void {
            if (! Cache::add(BroadcastDashboardStats::PENDING_CACHE_KEY, true, BroadcastDashboardStats::PENDING_TTL_SECONDS)) {
                return;
            }

            dispatch(new BroadcastDashboardStats)->delay(now()->addSeconds(BroadcastDashboardStats::DELAY_SECONDS));
        });
    }
}
