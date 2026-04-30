<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\ServiceWarning;

test('via defaults to database + broadcast for users with no overrides', function (): void {
    $user = User::factory()->create();
    $notification = new ServiceWarning('sabnzbd', 'Disk full', 'Less than 1 GB free', 'disk_full');

    expect($notification->via($user))->toEqual(['database', 'broadcast']);
});

test('disk_full maps to error severity for preference lookup', function (): void {
    $user = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $user->id,
        'notification_class' => ServiceWarning::class,
        'severity' => 'error',
        'database' => true,
        'broadcast' => false,
        'mail' => false,
        'ntfy' => false,
    ]);

    $notification = new ServiceWarning('sabnzbd', 'Disk full', '...', 'disk_full');

    expect($notification->via($user))->toEqual(['database']);
});

test('warning level resolves to warning severity prefs', function (): void {
    $user = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $user->id,
        'notification_class' => ServiceWarning::class,
        'severity' => 'warning',
        'database' => false,
        'broadcast' => true,
        'mail' => false,
        'ntfy' => false,
    ]);

    $notification = new ServiceWarning('sonarr', 'IndexerStatusCheck', '...', 'warning');

    expect($notification->via($user))->toEqual(['broadcast']);
});

test('mail and ntfy toggles are stored but never emitted yet', function (): void {
    $user = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $user->id,
        'notification_class' => ServiceWarning::class,
        'severity' => 'error',
        'database' => false,
        'broadcast' => false,
        'mail' => true,
        'ntfy' => true,
    ]);

    $notification = new ServiceWarning('sonarr', 'IndexerStatusCheck', '...', 'error');

    // mail/ntfy aren't wired up — caller would otherwise dispatch through
    // a nonexistent channel and 500.
    expect($notification->via($user))->toEqual([]);
});
