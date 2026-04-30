<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use Illuminate\Notifications\Messages\BroadcastMessage;

test('via includes both database and broadcast channels', function (): void {
    $notification = new AiBudgetSoftLimitReached(spendUsd: 12.34, softCapUsd: 20.0);

    expect($notification->via(new User))->toEqual(['database', 'broadcast']);
});

test('toBroadcast mirrors the database payload', function (): void {
    $notification = new AiBudgetSoftLimitReached(spendUsd: 12.34, softCapUsd: 20.0);

    $broadcastMessage = $notification->toBroadcast(new User);

    expect($broadcastMessage)->toBeInstanceOf(BroadcastMessage::class);
    expect($broadcastMessage->data)->toBe($notification->toArray(new User));
});
