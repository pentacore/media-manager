<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ServiceConnection;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceLatestVersionFetched implements ShouldBroadcast
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
        return 'ServiceLatestVersionFetched';
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
            'name' => $this->serviceConnection->name,
            'type' => $this->serviceConnection->type->value,
            'version' => $version,
            'latest_version' => $latest,
            'update_available' => $version !== null && $latest !== null && $version !== $latest,
        ];
    }
}
