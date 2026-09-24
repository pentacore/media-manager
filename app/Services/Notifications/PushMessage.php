<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Channel-neutral push payload every notification produces via toPush().
 * Each push channel (ntfy, Discord, Telegram, webhook) formats this for
 * its own API, so notifications never know about channel specifics.
 */
final readonly class PushMessage
{
    /**
     * @param  'info'|'warning'|'error'  $severity
     */
    public function __construct(
        public string $severity,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    /**
     * @return array{severity: string, title: string, body: string, url: ?string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }
}
