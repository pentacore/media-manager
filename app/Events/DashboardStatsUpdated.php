<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardStatsUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $activeServices,
        public int $totalServices,
        public int $recentWebhooks,
        public int $pendingActions,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('dashboard');
    }

    public function broadcastAs(): string
    {
        return 'DashboardStatsUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'activeServices' => $this->activeServices,
            'totalServices' => $this->totalServices,
            'recentWebhooks' => $this->recentWebhooks,
            'pendingActions' => $this->pendingActions,
            'updatedAt' => now()->toISOString(),
        ];
    }
}
