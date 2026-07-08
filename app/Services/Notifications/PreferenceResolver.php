<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\ServiceUpdateAvailable;

/**
 * Resolves the channel list for a (user, notification class, severity)
 * tuple. Anything the user hasn't explicitly toggled falls back to the
 * defaults below — database + broadcast on by default; mail/ntfy off
 * unless toggled — all four deliver.
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
     * Per-notification-class default overrides, merged over DEFAULTS when
     * the user has no explicit preference row. ServiceUpdateAvailable was
     * always mailed before it joined the preference system; keep that
     * behavior for unset preferences.
     *
     * @var array<class-string, array<string, bool>>
     */
    private const array CLASS_DEFAULTS = [
        ServiceUpdateAvailable::class => ['mail' => true],
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
            : [...self::DEFAULTS, ...(self::CLASS_DEFAULTS[$notificationClass] ?? [])];

        $enabled = array_values(array_filter(
            self::CHANNELS,
            static fn (string $channel): bool => $flags[$channel],
        ));

        // 'ntfy' is a custom channel: Laravel resolves it by class name.
        return array_map(
            static fn (string $channel): string => $channel === 'ntfy' ? NtfyChannel::class : $channel,
            $enabled,
        );
    }
}
