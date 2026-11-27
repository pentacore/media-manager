<?php

declare(strict_types=1);

use App\Events\UserNotificationStored;
use App\Models\NotificationDestination;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Queue::fake (not Event::fake) keeps the real dispatcher in play, so the
// after-commit gate runs and a broadcast shows up as a queued BroadcastEvent
// only once the transaction has committed.
function storedNotificationBroadcasts(): array
{
    return Queue::pushed(BroadcastEvent::class)
        ->filter(fn (BroadcastEvent $broadcastEvent): bool => $broadcastEvent->event instanceof UserNotificationStored)
        ->map(fn (BroadcastEvent $broadcastEvent): UserNotificationStored => $broadcastEvent->event)
        ->values()
        ->all();
}

test('storing a database notification for a user broadcasts it on their channel once', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    $user->notify(new AiBudgetSoftLimitReached(spendUsd: 1.0, softCapUsd: 2.0));

    $events = storedNotificationBroadcasts();
    expect($events)->toHaveCount(1);
    expect($events[0]->userId)->toBe($user->id);
    expect($events[0]->notificationId)->toBe($user->notifications()->sole()->id);
    expect($events[0]->broadcastOn())->toEqual(new PrivateChannel('App.Models.User.'.$user->id));
    expect($events[0]->broadcastAs())->toBe('UserNotificationStored');
    expect($events[0]->broadcastWith())->toBe(['id' => $events[0]->notificationId]);
});

test('it still fires when the user preferences turn the broadcast channel off', function (): void {
    Queue::fake();
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

    $user->notify(new AiBudgetSoftLimitReached(spendUsd: 1.0, softCapUsd: 2.0));

    expect(storedNotificationBroadcasts())->toHaveCount(1);
});

test('a notification row whose notifiable is not a user dispatches nothing', function (): void {
    Queue::fake();
    $destination = NotificationDestination::factory()->create();

    $destination->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => AiBudgetSoftLimitReached::class,
        'data' => ['title' => 'x'],
    ]);

    expect($destination->notifications()->count())->toBe(1);
    expect(storedNotificationBroadcasts())->toBe([]);
});

test('a rolled back transaction dispatches nothing', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user): void {
            $user->notify(new AiBudgetSoftLimitReached(spendUsd: 1.0, softCapUsd: 2.0));

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($user->notifications()->count())->toBe(0);
    expect(storedNotificationBroadcasts())->toBe([]);
});
