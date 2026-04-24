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
        // Do NOT broadcast raw exception messages or stack traces — those can
        // leak sensitive paths, tokens, or server-internal details. Full context
        // remains in the DB for admins viewing the Actions page.
        $result = $this->actionRequest->result ?? [];
        $safeResult = [
            'success' => $result['success'] ?? null,
            'reason' => $result['reason'] ?? null,
        ];

        return [
            'id' => $this->actionRequest->id,
            'status' => $this->actionRequest->status->value,
            'result' => $safeResult,
            'updated_at' => $this->actionRequest->updated_at->toISOString(),
        ];
    }
}
