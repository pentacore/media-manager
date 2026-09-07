<?php

declare(strict_types=1);

use App\Enums\NotificationSeverity;
use App\Enums\PushChannelType;
use App\Models\NotificationDestination;
use Illuminate\Support\Facades\DB;

test('config is encrypted at rest and decrypted on read', function (): void {
    $destination = NotificationDestination::factory()->discord()->create();

    $raw = DB::table('notification_destinations')->where('id', $destination->id)->value('config');

    expect($raw)->not->toContain('discord.com')
        ->and($destination->fresh()->config['url'])->toStartWith('https://discord.com/api/webhooks/');
});

test('accepts honours the enabled flag and the minimum severity', function (): void {
    $warning = NotificationDestination::factory()->discord()->minSeverity(NotificationSeverity::Warning)->create();
    $disabled = NotificationDestination::factory()->discord()->disabled()->create();

    expect($warning->accepts('error'))->toBeTrue()
        ->and($warning->accepts('warning'))->toBeTrue()
        ->and($warning->accepts('info'))->toBeFalse()
        ->and($disabled->accepts('error'))->toBeFalse();
});

test('routing methods read the channel config', function (): void {
    expect(NotificationDestination::factory()->ntfy()->create()->routeNotificationForNtfy())->toBe('mm-global')
        ->and(NotificationDestination::factory()->discord()->create()->routeNotificationForDiscord())->toStartWith('https://discord.com/')
        ->and(NotificationDestination::factory()->telegram()->create()->routeNotificationForTelegram())->toBe('-1001')
        ->and(NotificationDestination::factory()->webhook()->create()->routeNotificationForWebhook())->toBe(['url' => 'https://hooks.example.com/mm', 'secret' => 's3cret'])
        ->and(NotificationDestination::factory()->discord()->create()->routeNotificationForTelegram())->toBeNull();
});

test('configHint masks everything but the last four characters', function (): void {
    expect(NotificationDestination::factory()->webhook()->create(['config' => ['url' => 'https://hooks.example.com/abcd1234', 'secret' => null]])->configHint())->toBe('…1234')
        ->and(NotificationDestination::factory()->telegram()->create()->configHint())->toBe('…1001');
});

test('enabled scope excludes disabled destinations', function (): void {
    NotificationDestination::factory()->discord()->create();
    NotificationDestination::factory()->discord()->disabled()->create();

    expect(NotificationDestination::query()->enabled()->count())->toBe(1)
        ->and(NotificationDestination::factory()->discord()->create()->channel)->toBe(PushChannelType::Discord);
});
