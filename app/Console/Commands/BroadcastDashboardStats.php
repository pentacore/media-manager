<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Broadcast current dashboard statistics via WebSocket')]
#[Signature('dashboard:broadcast-stats')]
class BroadcastDashboardStats extends Command
{
    public function handle(DashboardStatsService $dashboardStatsService): void
    {
        $dashboardStatsService->broadcast();

        $this->info('Dashboard stats broadcast successfully.');
    }
}
