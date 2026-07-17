<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Notifications\NtfyMessage;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

final class SubtitleCaseNeedsReview extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $subtitleCaseId,
        public readonly string $displayName,
        public readonly string $summary,
        public readonly string $category,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return resolve(PreferenceResolver::class)
            ->channelsFor($notifiable, self::class, 'warning');
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'service' => 'bazarr',
            'title' => 'Subtitle case needs review',
            'message' => sprintf('%s: %s', $this->displayName, $this->summary),
            'link' => '/bazarr/escalations',
            'subtitle_case_id' => $this->subtitleCaseId,
            'category' => $this->category,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * @return array{title: string, message: string, priority: int, tags: list<string>, click?: string}
     */
    public function toNtfy(object $notifiable): array
    {
        return NtfyMessage::for(
            'warning',
            '[Bazarr] Subtitle case needs review',
            sprintf('%s: %s', $this->displayName, $this->summary),
            url('/bazarr/escalations'),
        );
    }
}
