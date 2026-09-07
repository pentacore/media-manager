<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Support\Facades\DB;

test('the channel list includes the push channels and maps them to classes', function (): void {
    expect(PreferenceResolver::CHANNELS)->toBe(['database', 'broadcast', 'mail', 'ntfy', 'discord', 'telegram', 'webhook'])
        ->and(PreferenceResolver::CHANNEL_CLASSES)->toBe([
            'ntfy' => NtfyChannel::class,
            'discord' => DiscordChannel::class,
            'telegram' => TelegramChannel::class,
            'webhook' => WebhookChannel::class,
        ]);
});

test('new push channels default off for users', function (): void {
    $user = User::factory()->create();

    expect(resolve(PreferenceResolver::class)->channelsFor($user, ServiceWarning::class, 'warning'))
        ->toBe(['database', 'broadcast']);
});

test('a preference row can enable discord, telegram and webhook', function (): void {
    $user = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $user->id,
        'notification_class' => ServiceWarning::class,
        'severity' => 'error',
        'database' => false,
        'broadcast' => false,
        'mail' => false,
        'ntfy' => false,
        'discord' => true,
        'telegram' => true,
        'webhook' => true,
    ]);

    expect(resolve(PreferenceResolver::class)->channelsFor($user, ServiceWarning::class, 'error'))
        ->toBe([DiscordChannel::class, TelegramChannel::class, WebhookChannel::class]);
});

test('user routing returns the stored destinations or null', function (): void {
    $user = User::factory()->create([
        'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
        'telegram_chat_id' => '-1001',
        'webhook_url' => 'https://hooks.example.com/mm',
        'webhook_secret' => 's3cret',
    ]);
    $bare = User::factory()->create();

    expect($user->routeNotificationForDiscord())->toBe('https://discord.com/api/webhooks/1/abc')
        ->and($user->routeNotificationForTelegram())->toBe('-1001')
        ->and($user->routeNotificationForWebhook())->toBe(['url' => 'https://hooks.example.com/mm', 'secret' => 's3cret'])
        ->and($bare->routeNotificationForDiscord())->toBeNull()
        ->and($bare->routeNotificationForTelegram())->toBeNull()
        ->and($bare->routeNotificationForWebhook())->toBeNull();
});

test('secret user destinations are encrypted at rest and hidden from arrays', function (): void {
    $user = User::factory()->create(['discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc', 'webhook_secret' => 's3cret']);

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->discord_webhook_url)->not->toBe('https://discord.com/api/webhooks/1/abc')
        ->and($raw->webhook_secret)->not->toBe('s3cret')
        ->and($user->toArray())->not->toHaveKey('discord_webhook_url')
        ->not->toHaveKey('webhook_url')
        ->not->toHaveKey('webhook_secret');
});
