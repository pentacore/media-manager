<?php

declare(strict_types=1);

namespace App\Events\Ai;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;

class AgentStepUpdate implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const string STATUS_STARTED = 'started';

    public const string STATUS_FINISHED = 'finished';

    public CarbonImmutable $occurredAt;

    public function __construct(
        public int $userId,
        public string $conversationId,
        public string $toolName,
        public string $status,
    ) {
        $this->occurredAt = Date::now();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(sprintf('ai-chat.%d.%s', $this->userId, $this->conversationId));
    }

    public function broadcastAs(): string
    {
        return 'AgentStepUpdate';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'tool_name' => $this->toolName,
            'status' => $this->status,
            'occurred_at' => $this->occurredAt->toISOString(),
        ];
    }
}
