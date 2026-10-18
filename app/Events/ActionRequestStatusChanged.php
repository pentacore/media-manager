<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ActionRequest;
use App\Services\Actions\ActionRequestBrowserPayload;
use App\Services\Actions\PendingActionCount;
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
     * settled by a bulk review). `pending_version` is the newest
     * `status_version` that count reflects (`PendingActionCount::snapshot()`),
     * so a count that lands late never overwrites a newer one.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $pending = resolve(PendingActionCount::class)->snapshot();

        return [
            'id' => $this->actionRequest->id,
            'status' => $this->actionRequest->status->value,
            'result' => resolve(ActionRequestBrowserPayload::class)->safeResult($this->actionRequest),
            'updated_at' => $this->actionRequest->updated_at->toISOString(),
            // The row's status version: the browser orders live changes by it
            // (a database sequence, so it survives a same-second change and
            // host clock skew). The model was re-read when the queued
            // broadcast ran (SerializesModels), so it is the stored number.
            'status_version' => $this->actionRequest->status_version,
            'pending_count' => $pending['count'],
            'pending_version' => $pending['version'],
        ];
    }
}
