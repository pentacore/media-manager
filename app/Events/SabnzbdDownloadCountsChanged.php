<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SabnzbdDownloadCountsChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public int $queued, public int $completed) {}

    public function broadcastOn(): PrivateChannel
    {
        // Reuses the dashboard channel — same auth scope as the
        // intervention badge and the same set of subscribers (anyone
        // with the sidebar open).
        return new PrivateChannel('dashboard');
    }

    public function broadcastAs(): string
    {
        return 'SabnzbdDownloadCountsChanged';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['queued' => $this->queued, 'completed' => $this->completed];
    }
}
