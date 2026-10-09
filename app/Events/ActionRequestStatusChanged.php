<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ActionRequest;
use App\Services\Actions\ActionRequestBrowserPayload;
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
        return new PrivateChannel('members.actions');
    }

    public function broadcastAs(): string
    {
        return 'ActionRequestStatusChanged';
    }

    /**
     * The result is narrowed to ActionRequestBrowserPayload::safeResult():
     * raw exception messages can carry paths, tokens or server internals,
     * and admins read the full result from the Action Queue's page props.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->actionRequest->id,
            'status' => $this->actionRequest->status->value,
            'result' => resolve(ActionRequestBrowserPayload::class)->safeResult($this->actionRequest),
            'updated_at' => $this->actionRequest->updated_at->toISOString(),
        ];
    }
}
