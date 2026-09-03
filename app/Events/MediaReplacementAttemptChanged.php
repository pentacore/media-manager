<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MediaReplacementAttempt;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever an attempt row changes state (executor, tracker, reconcile
 * command, operator actions). Consumed in-process by the subtitle-case
 * listener and broadcast to admins so the attempts pages, the sidebar badge
 * and the Action Queue refresh without polling.
 */
final class MediaReplacementAttemptChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public MediaReplacementAttempt $mediaReplacementAttempt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.media-replacement');
    }

    public function broadcastAs(): string
    {
        return 'MediaReplacementAttemptChanged';
    }

    /**
     * Scalars only. The target/candidate/verification JSON stays in the DB —
     * it carries file paths and release internals the socket never needs.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $attempt = $this->mediaReplacementAttempt;
        $attempt->loadMissing('serviceConnection:id,type');
        $target = is_array($attempt->target) ? $attempt->target : [];
        $displayName = $target['display_name'] ?? null;

        return [
            'id' => $attempt->id,
            'action_request_id' => $attempt->action_request_id,
            'status' => $attempt->status->value,
            'failure_reason' => $attempt->failure_reason,
            'scope' => $attempt->scope,
            'service_type' => $attempt->serviceConnection?->type->value,
            'display_name' => is_string($displayName) ? $displayName : null,
            'acknowledged' => $attempt->acknowledged_at !== null,
            'completed_at' => $attempt->completed_at?->toISOString(),
            'updated_at' => $attempt->updated_at?->toISOString(),
            'attention_unacknowledged' => MediaReplacementAttempt::unacknowledgedAttentionCount(),
        ];
    }
}
