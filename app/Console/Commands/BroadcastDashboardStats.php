<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Console\Command;
use Override;

class BroadcastDashboardStats extends Command
{
    #[Override]
    protected $signature = 'dashboard:broadcast-stats';

    #[Override]
    protected $description = 'Broadcast current dashboard statistics via WebSocket';

    public function handle(DashboardStatsService $stats): void
    {
        $stats->broadcast();

        $this->info('Dashboard stats broadcast successfully.');
    }
}
