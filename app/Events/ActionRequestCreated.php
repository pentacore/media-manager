<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ActionRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActionRequestCreated implements ShouldBroadcast
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
        return 'ActionRequestCreated';
    }

    /**
     * Mirrors ActionRequestResource so the frontend can upsert realtime rows
     * into the table without missing fields. Result is narrowed to safe keys
     * (no exception/message text leaking off-server).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->actionRequest->loadMissing(['approvedByUser', 'webhookEvent.serviceConnection']);

        $result = $this->actionRequest->result;
        $safeResult = $result === null ? null : [
            'success' => $result['success'] ?? null,
            'reason' => $result['reason'] ?? null,
        ];

        return [
            'id' => $this->actionRequest->id,
            'type' => $this->actionRequest->type,
            'source_service' => $this->actionRequest->source_service,
            'target_service' => $this->actionRequest->target_service,
            'status' => $this->actionRequest->status->value,
            'requires_approval' => $this->actionRequest->requires_approval,
            'payload' => $this->actionRequest->payload,
            'result' => $safeResult,
            'approved_by' => $this->actionRequest->approvedByUser?->name,
            'webhook_source' => $this->actionRequest->webhookEvent?->serviceConnection?->name,
            'created_at' => $this->actionRequest->created_at?->toISOString(),
            'updated_at' => $this->actionRequest->updated_at?->toISOString(),
        ];
    }
}
