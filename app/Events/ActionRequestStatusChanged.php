<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\ActionRequestStatus;
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
     * `pending_count` is every pending request at the time the queued
     * broadcast runs: the sidebar badge takes it as is, so it never drifts
     * the way per-id deltas did (a request pending before the tab opened, or
     * settled by a bulk review).
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
            'pending_count' => ActionRequest::query()->where('status', ActionRequestStatus::Pending)->count(),
        ];
    }
}
