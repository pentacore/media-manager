<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\EmbyActivity;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmbyPlaybackUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public EmbyActivity $embyActivity) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('emby.activity');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->embyActivity->id,
            'media_type' => $this->embyActivity->media_type,
            'media_title' => $this->embyActivity->media_title,
            'series_title' => $this->embyActivity->series_title,
            'action' => $this->embyActivity->action,
            'emby_username' => $this->embyActivity->embyUserLink->emby_username,
            'updated_at' => $this->embyActivity->updated_at->toISOString(),
        ];
    }
}
