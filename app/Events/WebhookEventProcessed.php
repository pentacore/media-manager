<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WebhookEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebhookEventProcessed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public WebhookEvent $webhookEvent) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('dashboard');
    }

    public function broadcastAs(): string
    {
        return 'WebhookEventProcessed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->webhookEvent->id,
            'processed_at' => $this->webhookEvent->processed_at?->toISOString(),
        ];
    }
}
