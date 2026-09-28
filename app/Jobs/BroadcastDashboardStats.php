<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;

/**
 * Debounced dashboard-stats rebroadcast scheduled by RebroadcastDashboardStats.
 * Forgets the pending marker before querying, so any upstream event that
 * finds the marker set is guaranteed to be counted by this run, and any
 * event after the forget schedules the next run: the trailing update of a
 * burst is never dropped.
 */
#[Tries(1)]
final class BroadcastDashboardStats implements ShouldQueue
{
    use Queueable;

    public const string PENDING_CACHE_KEY = 'dashboard-stats-broadcast:pending';

    /** Self-heal window if a scheduled job is lost. */
    public const int PENDING_TTL_SECONDS = 60;

    /** Coalesces a webhook burst into roughly one broadcast per second. */
    public const int DELAY_SECONDS = 1;

    public function handle(DashboardStatsService $dashboardStatsService): void
    {
        Cache::forget(self::PENDING_CACHE_KEY);

        $dashboardStatsService->broadcast();
    }
}
