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

test('toPush carries the version bump with info severity', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr']);
    $user = User::factory()->create();
    $message = new ServiceUpdateAvailable($connection, '4.1.0', '4.0.0')->toPush($user);

    expect($message->severity)->toBe('info')
        ->and($message->title)->toBe('Update available for Sonarr')
        ->and($message->body)->toBe('4.0.0 → 4.1.0')
        ->and($message->url)->toBe(route('monitoring.service-health'));
});
