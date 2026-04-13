<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ActionRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActionRequestStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ActionRequest $actionRequest) {}

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
            'id' => $this->actionRequest->id,
            'status' => $this->actionRequest->status->value,
            'result' => $this->actionRequest->result,
            'updated_at' => $this->actionRequest->updated_at->toISOString(),
        ];
    }
}
