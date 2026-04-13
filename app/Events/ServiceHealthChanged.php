<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ServiceConnection;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceHealthChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public ServiceConnection $serviceConnection,
        public string $status,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('services');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->serviceConnection->id,
            'name' => $this->serviceConnection->name,
            'type' => $this->serviceConnection->type->value,
            'is_active' => $this->serviceConnection->is_active,
            'status' => $this->status,
            'last_seen_at' => $this->serviceConnection->last_seen_at?->toISOString(),
        ];
    }
}
