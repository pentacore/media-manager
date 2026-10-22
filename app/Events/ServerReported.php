<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agent report was processed, so the server pages can reload their live figures.
 */
class ServerReported implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $serverId, public ?string $lastReportAt) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.servers');
    }

    public function broadcastAs(): string
    {
        return 'ServerReported';
    }

    /**
     * @return array{server_id: int, last_report_at: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'last_report_at' => $this->lastReportAt,
        ];
    }
}
