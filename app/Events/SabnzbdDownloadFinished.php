<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ServiceConnection;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SabnzbdDownloadFinished implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public ServiceConnection $serviceConnection,
        public string $name,
        public string $status,
        public ?string $failMessage,
        public ?string $nzoId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('members.sabnzbd');
    }

    public function broadcastAs(): string
    {
        return 'SabnzbdDownloadFinished';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'service_connection_id' => $this->serviceConnection->id,
            'name' => $this->name,
            'status' => $this->status,
            'fail_message' => $this->failMessage,
            'nzo_id' => $this->nzoId,
        ];
    }
}
