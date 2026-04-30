<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use Illuminate\Notifications\Messages\BroadcastMessage;

test('via defaults to database + broadcast for users with no overrides', function (): void {
    $notification = new AiBudgetSoftLimitReached(spendUsd: 12.34, softCapUsd: 20.0);
    $user = User::factory()->create();

    expect($notification->via($user))->toEqual(['database', 'broadcast']);
});

test('via honours per-user preference rows', function (): void {
    $user = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $user->id,
        'notification_class' => AiBudgetSoftLimitReached::class,
        'severity' => 'warning',
        'database' => true,
        'broadcast' => false,
        'mail' => false,
        'ntfy' => false,
    ]);

    $notification = new AiBudgetSoftLimitReached(spendUsd: 1.0, softCapUsd: 2.0);

    expect($notification->via($user))->toEqual(['database']);
});

test('toBroadcast mirrors the database payload', function (): void {
    $notification = new AiBudgetSoftLimitReached(spendUsd: 12.34, softCapUsd: 20.0);
    $user = User::factory()->create();

    $broadcastMessage = $notification->toBroadcast($user);

    expect($broadcastMessage)->toBeInstanceOf(BroadcastMessage::class);
    expect($broadcastMessage->data)->toBe($notification->toArray($user));
});
