<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Notifications\NtfyMessage;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AiBudgetSoftLimitReached extends Notification
{
    use Queueable;

    public function __construct(
        public readonly float $spendUsd,
        public readonly float $softCapUsd,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return resolve(PreferenceResolver::class)
            ->channelsFor($notifiable, self::class, 'warning');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'service' => 'system',
            'title' => 'AI soft budget limit reached',
            'message' => sprintf(
                'Spend $%0.2f of $%0.2f soft cap. Hard cap, if set, will halt AI requests when reached.',
                $this->spendUsd,
                $this->softCapUsd,
            ),
            'link' => '/admin/ai-usage',
            'spend_usd' => $this->spendUsd,
            'soft_cap_usd' => $this->softCapUsd,
        ];
    }

    /**
     * Mirrors toArray so the websocket payload matches what the
     * Notifications page renders for already-persisted rows.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * @return array{title: string, message: string, priority: int, tags: array<int, string>, click?: string}
     */
    public function toNtfy(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return NtfyMessage::for('warning', $data['title'], $data['message'], url('/admin/ai-usage'));
    }
}
