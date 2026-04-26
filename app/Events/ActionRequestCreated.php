<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\UserRole;
use App\Models\ActionRequest;
use App\Models\User;
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

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return User::query()
            ->whereIn('role', [UserRole::Admin->value, UserRole::Member->value])
            ->pluck('id')
            ->map(fn (int $id): PrivateChannel => new PrivateChannel('App.Models.User.'.$id))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'ActionRequestCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->actionRequest->id,
            'type' => $this->actionRequest->type,
            'source_service' => $this->actionRequest->source_service,
            'target_service' => $this->actionRequest->target_service,
            'status' => $this->actionRequest->status->value,
            'requires_approval' => $this->actionRequest->requires_approval,
            'created_at' => $this->actionRequest->created_at->toISOString(),
        ];
    }
}
