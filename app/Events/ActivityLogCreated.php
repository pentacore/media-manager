<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ActivityLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActivityLogCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ActivityLog $activityLog) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('activity');
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
