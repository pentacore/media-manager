<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceConnectionUpserted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ServiceConnection $serviceConnection) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('services');
    }

    public function broadcastAs(): string
    {
        return 'ServiceConnectionUpserted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $version = $this->serviceConnection->version;
        $latest = $this->serviceConnection->latest_version;

        return [
            'id' => $this->serviceConnection->id,
            'type' => $this->serviceConnection->type->value,
            'name' => $this->serviceConnection->name,
            'url' => $this->serviceConnection->url,
            'is_active' => $this->serviceConnection->is_active,
            'health_status' => ($this->serviceConnection->health_status ?? HealthStatus::Unknown)->value,
            'health_message' => $this->serviceConnection->health_message,
            'version' => $version,
            'latest_version' => $latest,
            'update_available' => $version !== null && $latest !== null && $version !== $latest,
            'last_seen_at' => $this->serviceConnection->last_seen_at?->toISOString(),
        ];
    }
}
