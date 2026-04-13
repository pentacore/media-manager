<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WebhookEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebhookReceived implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public WebhookEvent $webhookEvent) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->webhookEvent->id,
            'service_connection_id' => $this->webhookEvent->service_connection_id,
            'service_name' => $this->webhookEvent->serviceConnection->name,
            'service_type' => $this->webhookEvent->serviceConnection->type->value,
            'event_type' => $this->webhookEvent->event_type,
            'created_at' => $this->webhookEvent->created_at->toISOString(),
        ];
    }
}
