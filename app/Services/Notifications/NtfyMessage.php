<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Builds the ntfy JSON-publish payload fragment shared by every
 * notification's toNtfy(). Severity buckets map to ntfy integer
 * priorities (1-5) and a tag emoji.
 */
class NtfyMessage
{
    /**
     * @return array{title: string, message: string, priority: int, tags: array<int, string>, click?: string}
     */
    public static function for(string $severity, string $title, string $message, ?string $click = null): array
    {
        $payload = [
            'title' => $title,
            'message' => $message,
            'priority' => match ($severity) {
                'error' => 4,
                'warning' => 3,
                default => 2,
            },
            'tags' => [match ($severity) {
                'error' => 'rotating_light',
                'warning' => 'warning',
                default => 'information_source',
            }],
        ];

        if ($click !== null && $click !== '') {
            $payload['click'] = $click;
        }

        return $payload;
    }
}
