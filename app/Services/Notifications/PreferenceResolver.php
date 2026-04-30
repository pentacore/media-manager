<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Resolves the channel list for a (user, notification class, severity)
 * tuple. Anything the user hasn't explicitly toggled falls back to the
 * defaults below — currently database + broadcast on, mail/ntfy off.
 */
class PreferenceResolver
{
    /** Channels available across the whole pipeline. */
    public const array CHANNELS = ['database', 'broadcast', 'mail', 'ntfy'];

    /** Severities Laravel notifications will route through this resolver. */
    public const array SEVERITIES = ['info', 'warning', 'error'];

    private const array DEFAULTS = [
        'database' => true,
        'broadcast' => true,
        'mail' => false,
        'ntfy' => false,
    ];

    /**
     * @return array<int, string>
     */
    public function channelsFor(User $user, string $notificationClass, string $severity): array
    {
        $row = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('notification_class', $notificationClass)
            ->where('severity', $severity)
            ->first();

        $flags = $row instanceof NotificationPreference
            ? [
                'database' => $row->database,
                'broadcast' => $row->broadcast,
                'mail' => $row->mail,
                'ntfy' => $row->ntfy,
            ]
            : self::DEFAULTS;

        // mail + ntfy aren't wired up yet; surface them in storage but
        // never emit them from the dispatch path so a stale toggle from
        // a future flip-back doesn't try to send through a non-existent
        // channel.
        return array_values(array_filter(
            ['database', 'broadcast'],
            static fn (string $channel): bool => $flags[$channel],
        ));
    }
}
