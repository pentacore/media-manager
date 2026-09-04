<?php

declare(strict_types=1);

use App\Enums\PushChannelType;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;

test('each push channel type maps to its channel class', function (): void {
    expect(PushChannelType::Ntfy->channelClass())->toBe(NtfyChannel::class)
        ->and(PushChannelType::Discord->channelClass())->toBe(DiscordChannel::class)
        ->and(PushChannelType::Telegram->channelClass())->toBe(TelegramChannel::class)
        ->and(PushChannelType::Webhook->channelClass())->toBe(WebhookChannel::class);
});

test('config keys describe the destination fields per channel', function (): void {
    expect(PushChannelType::Ntfy->configKeys())->toBe(['topic'])
        ->and(PushChannelType::Discord->configKeys())->toBe(['url'])
        ->and(PushChannelType::Telegram->configKeys())->toBe(['chat_id'])
        ->and(PushChannelType::Webhook->configKeys())->toBe(['url', 'secret']);
});
