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
     * Fired after the queued handler returned — after any cache it clears
     * was cleared — so a page that reloads on it reads fresh rows. Pages
     * filter on `service_type`; `WebhookReceived` fires at receipt, before
     * processing, and is not a reload signal.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->webhookEvent->loadMissing('serviceConnection:id,type');

        return [
            'id' => $this->webhookEvent->id,
            'service_connection_id' => $this->webhookEvent->service_connection_id,
            'service_type' => $this->webhookEvent->serviceConnection->type->value,
            'event_type' => $this->webhookEvent->event_type,
            'processed_at' => $this->webhookEvent->processed_at?->toISOString(),
        ];
    }
}
