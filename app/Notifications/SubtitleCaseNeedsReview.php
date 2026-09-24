<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Notifications\PreferenceResolver;
use App\Services\Notifications\PushMessage;
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
            'link' => route('bazarr.escalations', absolute: false),
            'subtitle_case_id' => $this->subtitleCaseId,
            'category' => $this->category,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            severity: 'warning',
            title: '[Bazarr] Subtitle case needs review',
            body: sprintf('%s: %s', $this->displayName, $this->summary),
            url: route('bazarr.escalations'),
        );
    }
}
