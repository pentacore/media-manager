<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Notifications\PreferenceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic warning/error notification for incoming service webhooks
 * (Sonarr/Radarr health, SAB disk_full, etc). Channel selection is
 * delegated to PreferenceResolver so each user controls what reaches
 * them.
 */
class ServiceWarning extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $service,
        public readonly string $title,
        public readonly string $message,
        public readonly string $level,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return resolve(PreferenceResolver::class)
            ->channelsFor($notifiable, self::class, $this->severityKey());
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'service' => $this->service,
            'title' => $this->title,
            'message' => $this->message,
            'level' => $this->level,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /** Map raw `$level` strings to the canonical severity bucket. */
    public function severityKey(): string
    {
        return match ($this->level) {
            'error', 'disk_full' => 'error',
            'warning' => 'warning',
            default => 'info',
        };
    }
}
