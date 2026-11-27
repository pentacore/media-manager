<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\LiveChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A database notification row was stored for a user, whatever channels their
 * preferences enabled, so their notification bell and page can reload.
 */
final class UserNotificationStored implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public string $notificationId, public int $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return LiveChannels::user($this->userId);
    }

    public function broadcastAs(): string
    {
        return 'UserNotificationStored';
    }

    /**
     * @return array{id: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->notificationId];
    }
}
