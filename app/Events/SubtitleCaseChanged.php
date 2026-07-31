<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\SubtitleCaseStatus;
use App\Models\SubtitleCase;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SubtitleCaseChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public SubtitleCase $subtitleCase,
        public SubtitleCaseStatus $from,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('dashboard');
    }

    public function broadcastAs(): string
    {
        return 'SubtitleCaseChanged';
    }

    /**
     * @return array<string, int|string|null>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->subtitleCase->id,
            'bazarr_connection_id' => $this->subtitleCase->bazarr_connection_id,
            'from' => $this->from->value,
            'status' => $this->subtitleCase->status->value,
            'updated_at' => $this->subtitleCase->updated_at?->toISOString(),
        ];
    }
}
