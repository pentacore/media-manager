<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActionRequestStatus;
use App\Events\DashboardStatsUpdated;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Override;

class BroadcastDashboardStats extends Command
{
    #[Override]
    protected $signature = 'dashboard:broadcast-stats';

    #[Override]
    protected $description = 'Broadcast current dashboard statistics via WebSocket';

    public function handle(): void
    {
        event(new DashboardStatsUpdated(activeServices: ServiceConnection::where('is_active', true)->count(), totalServices: ServiceConnection::count(), recentWebhooks: WebhookEvent::where('created_at', '>=', now()->subDay())->count(), pendingActions: ActionRequest::where('status', ActionRequestStatus::Pending)->count()));

        $this->info('Dashboard stats broadcast successfully.');
    }
}
