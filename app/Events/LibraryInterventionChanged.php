<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever the count of *arr download-queue items requiring
 * manual intervention changes (recomputed by InterventionCounter).
 * The sidebar listens for this so the badge updates without a full
 * page reload.
 */
class LibraryInterventionChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public int $count) {}

    public function broadcastOn(): PrivateChannel
    {
        // Reuse the dashboard channel so any authenticated user with the
        // sidebar open receives it; the visible badge itself is gated by
        // the existing role checks in the Vue layer.
        return new PrivateChannel('dashboard');
    }

    public function broadcastAs(): string
    {
        return 'LibraryInterventionChanged';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['count' => $this->count];
    }
}
