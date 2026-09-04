<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Notifications\PreferenceResolver;
use App\Services\Notifications\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when the autonomous DecisionAgent proposes or takes action on an
 * inbound event. Routed through PreferenceResolver like other admin-facing
 * service notifications.
 */
class DecisionAgentActed extends Notification
{
    use Queueable;

    /**
     * @param  'suggested'|'acted'|'mixed'  $disposition
     */
    public function __construct(
        public readonly string $disposition,
        public readonly int $actionCount,
        public readonly string $summary,
        public readonly ?string $eventLabel = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return resolve(PreferenceResolver::class)
            ->channelsFor($notifiable, self::class, 'info');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'service' => 'system',
            'title' => $this->title(),
            'message' => $this->summary,
            'link' => '/actions/requests',
            'disposition' => $this->disposition,
            'action_count' => $this->actionCount,
            'event' => $this->eventLabel,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(severity: 'info', title: $this->title(), body: $this->summary, url: route('actions.requests.index'));
    }

    private function title(): string
    {
        $event = $this->eventLabel !== null && $this->eventLabel !== ''
            ? sprintf(' for %s', $this->eventLabel)
            : '';

        return match ($this->disposition) {
            'acted' => sprintf('Decision agent took %d action(s)%s', $this->actionCount, $event),
            'suggested' => sprintf('Decision agent suggested %d action(s)%s', $this->actionCount, $event),
            default => sprintf('Decision agent queued %d action(s)%s', $this->actionCount, $event),
        };
    }
}
