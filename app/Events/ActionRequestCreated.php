<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Services\Actions\ActionRequestBrowserPayload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActionRequestCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
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
     * into the table without missing fields. The result is narrowed to
     * ActionRequestBrowserPayload::safeResult() (no exception or message
     * text leaves the server).
     *
     * `pending_count` is every pending request at the time the queued
     * broadcast runs: the sidebar badge takes it as is, so it never drifts
     * the way per-id deltas did (a request pending before the tab opened, or
     * settled by a bulk review).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->actionRequest->loadMissing(['approvedByUser', 'webhookEvent.serviceConnection']);

        return [
            'id' => $this->actionRequest->id,
            'type' => $this->actionRequest->type,
            'origin' => $this->actionRequest->origin,
            ...resolve(ActionRequestBrowserPayload::class)->description($this->actionRequest),
            'source_service' => $this->actionRequest->source_service,
            'target_service' => $this->actionRequest->target_service,
            'status' => $this->actionRequest->status->value,
            'requires_approval' => $this->actionRequest->requires_approval,
            'payload' => resolve(ActionRequestBrowserPayload::class)->for($this->actionRequest),
            'result' => resolve(ActionRequestBrowserPayload::class)->safeResult($this->actionRequest),
            'approved_by' => $this->actionRequest->approvedByUser?->name,
            'webhook_source' => $this->actionRequest->webhookEvent?->serviceConnection?->name,
            'created_at' => $this->actionRequest->created_at?->toISOString(),
            'updated_at' => $this->actionRequest->updated_at?->toISOString(),
            'pending_count' => ActionRequest::query()->where('status', ActionRequestStatus::Pending)->count(),
        ];
    }
}
