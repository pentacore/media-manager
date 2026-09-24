<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;

enum PushChannelType: string
{
    use EnumUtils;

    case Ntfy = 'ntfy';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Ntfy => 'ntfy',
            self::Discord => 'Discord',
            self::Telegram => 'Telegram',
            self::Webhook => 'Webhook',
        };
    }

    /**
     * @return class-string<PushChannel>
     */
    public function channelClass(): string
    {
        return match ($this) {
            self::Ntfy => NtfyChannel::class,
            self::Discord => DiscordChannel::class,
            self::Telegram => TelegramChannel::class,
            self::Webhook => WebhookChannel::class,
        };
    }

    /**
     * Keys a destination's encrypted config array holds for this channel.
     *
     * @return list<string>
     */
    public function configKeys(): array
    {
        return match ($this) {
            self::Ntfy => ['topic'],
            self::Discord => ['url'],
            self::Telegram => ['chat_id'],
            self::Webhook => ['url', 'secret'],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
