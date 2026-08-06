<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Notifications\NtfyMessage;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies admins about a media subtitle replacement. MediaReplacementTracker
 * sends it when an attempt reaches a terminal or needs-attention state
 * (verified, failed, needs_attention); ImportedSubtitleAuditor also sends it for
 * the automatic check's outcomes — a replacement requested, an import whose file
 * could not be identified, a target that hit its attempt cap, and a missing
 * language with no eligible replacement. Channel selection is delegated to
 * PreferenceResolver so each user controls what reaches them.
 */
class MediaReplacementStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $service,
        public readonly string $title,
        public readonly string $message,
        public readonly string $level,
        public readonly string $url = '/actions/requests',
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
            'url' => $this->url,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * @return array{title: string, message: string, priority: int, tags: array<int, string>, click?: string}
     */
    public function toNtfy(object $notifiable): array
    {
        return NtfyMessage::for(
            $this->severityKey(),
            sprintf('[%s] %s', $this->service, $this->title),
            $this->message,
            url($this->url),
        );
    }

    /** Map the replacement level to the canonical severity bucket. */
    public function severityKey(): string
    {
        return match ($this->level) {
            'error' => 'error',
            'warning' => 'warning',
            default => 'info',
        };
    }
}
