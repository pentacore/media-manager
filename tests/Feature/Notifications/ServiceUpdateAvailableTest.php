<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\ServiceUpdateAvailable;

test('via defaults to database + broadcast + mail with no preference row', function (): void {
    $user = User::factory()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();
    $notification = new ServiceUpdateAvailable($connection, '4.1.0', '4.0.0');

    expect($notification->via($user))->toEqual(['database', 'broadcast', 'mail']);
});

test('via respects an explicit preference row over the class default', function (): void {
    $user = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $user->id,
        'notification_class' => ServiceUpdateAvailable::class,
        'severity' => 'info',
        'database' => true,
        'broadcast' => false,
        'mail' => false,
        'ntfy' => true,
    ]);
    $connection = ServiceConnection::factory()->sonarr()->create();
    $notification = new ServiceUpdateAvailable($connection, '4.1.0', '4.0.0');

    expect($notification->via($user))->toEqual(['database', NtfyChannel::class]);
});

test('toNtfy carries the version bump with info priority', function (): void {
    $user = User::factory()->create();
    $connection = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr Main']);
    $payload = new ServiceUpdateAvailable($connection, '4.1.0', '4.0.0')->toNtfy($user);

    expect($payload['priority'])->toBe(2)
        ->and($payload['title'])->toContain('Sonarr Main')
        ->and($payload['message'])->toContain('4.1.0')
        ->and($payload['click'])->toContain('/monitoring/service-health');
});
