<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\LiveChannels;
use App\Models\ServiceConnection;
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

    /**
     * The dashboard feed, plus the per-service and per-connection channels when
     * the connection still exists.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [LiveChannels::dashboard()];

        $this->webhookEvent->loadMissing('serviceConnection:id,type');
        /** @var ServiceConnection|null $serviceConnection The row can be gone by the time a queued broadcast resolves its channels. */
        $serviceConnection = $this->webhookEvent->serviceConnection;

        if ($serviceConnection !== null) {
            $channels[] = LiveChannels::webhooks($serviceConnection->type);
            $channels[] = LiveChannels::webhookConnection($serviceConnection->type, $serviceConnection->id);
        }

        return $channels;
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
        /** @var ServiceConnection|null $serviceConnection The row can be gone by the time a queued broadcast serializes. */
        $serviceConnection = $this->webhookEvent->serviceConnection;

        return [
            'id' => $this->webhookEvent->id,
            'service_connection_id' => $this->webhookEvent->service_connection_id,
            'service_type' => $serviceConnection?->type->value,
            'event_type' => $this->webhookEvent->event_type,
            'processed_at' => $this->webhookEvent->processed_at?->toISOString(),
        ];
    }
}
