<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationDestination;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\ServiceUpdateAvailable;

/**
 * Resolves the channel list for a (user, notification class, severity)
 * tuple. Anything the user hasn't explicitly toggled falls back to the
 * defaults below — database + broadcast on by default; mail and the push
 * channels off unless toggled — all of them deliver.
 */
class PreferenceResolver
{
    /** Channels available across the whole pipeline. */
    public const array CHANNELS = ['database', 'broadcast', 'mail', 'ntfy', 'discord', 'telegram', 'webhook'];

    /**
     * Custom push channels: Laravel resolves them by class name, the
     * preference table stores them by short name.
     *
     * @var array<string, class-string<PushChannel>>
     */
    public const array CHANNEL_CLASSES = [
        'ntfy' => NtfyChannel::class,
        'discord' => DiscordChannel::class,
        'telegram' => TelegramChannel::class,
        'webhook' => WebhookChannel::class,
    ];

    /** Severities Laravel notifications will route through this resolver. */
    public const array SEVERITIES = ['info', 'warning', 'error'];

    private const array DEFAULTS = [
        'database' => true,
        'broadcast' => true,
        'mail' => false,
        'ntfy' => false,
        'discord' => false,
        'telegram' => false,
        'webhook' => false,
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
     * The default channel flags for a notification class when the user has
     * no explicit preference row. Used by the settings UI to seed toggles.
     *
     * @return array<string, bool>
     */
    public function defaultsFor(string $notificationClass): array
    {
        return [...self::DEFAULTS, ...(self::CLASS_DEFAULTS[$notificationClass] ?? [])];
    }

    /**
     * @return array<int, string>
     */
    public function channelsFor(User|NotificationDestination $notifiable, string $notificationClass, string $severity): array
    {
        if ($notifiable instanceof NotificationDestination) {
            return $notifiable->accepts($severity) ? [$notifiable->channel->channelClass()] : [];
        }

        $row = NotificationPreference::query()
            ->where('user_id', $notifiable->id)
            ->where('notification_class', $notificationClass)
            ->where('severity', $severity)
            ->first();

        $flags = $row instanceof NotificationPreference
            ? [
                'database' => $row->database,
                'broadcast' => $row->broadcast,
                'mail' => $row->mail,
                'ntfy' => $row->ntfy,
                'discord' => $row->discord,
                'telegram' => $row->telegram,
                'webhook' => $row->webhook,
            ]
            : [...self::DEFAULTS, ...(self::CLASS_DEFAULTS[$notificationClass] ?? [])];

        $enabled = array_values(array_filter(
            self::CHANNELS,
            static fn (string $channel): bool => $flags[$channel],
        ));

        return array_map(
            static fn (string $channel): string => self::CHANNEL_CLASSES[$channel] ?? $channel,
            $enabled,
        );
    }
}
