<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\ActivityLogCategory;
use App\Models\ActivityLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after commit: a row written inside a transaction that rolls
 * back must never reach a browser, and the queued broadcast job must never
 * try to restore a row that does not exist.
 */
class ActivityLogCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ActivityLog $activityLog) {}

    /**
     * Audit rows are admin-only: they go out on `activity.audit` and never on
     * the `activity` channel every signed-in user may join.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->activityLog->isAudit() ? 'activity.audit' : 'activity');
    }

    public function broadcastAs(): string
    {
        return 'ActivityLogCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->activityLog->loadMissing(['user:id,name', 'serviceConnection:id,name,type']);

        return [
            'id' => $this->activityLog->id,
            'action' => $this->activityLog->action,
            'category' => $this->activityLog->isAudit() ? ActivityLogCategory::Audit->value : ActivityLogCategory::Activity->value,
            'description' => $this->activityLog->description,
            'user_name' => $this->activityLog->user?->name,
            'service_id' => $this->activityLog->service_connection_id,
            'service_name' => $this->activityLog->serviceConnection?->name,
            'service_type' => $this->activityLog->serviceConnection?->type->value,
            'subject_type' => $this->activityLog->subject_type,
            'subject_id' => $this->activityLog->subject_id,
            'metadata' => $this->activityLog->metadata,
            'created_at' => $this->activityLog->created_at?->toISOString(),
        ];
    }
}
