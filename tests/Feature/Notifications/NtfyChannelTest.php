<?php

declare(strict_types=1);

use App\Models\User;

test('routeNotificationForNtfy returns the topic or null', function (): void {
    $user = User::factory()->create(['ntfy_topic' => 'my-topic']);
    $bare = User::factory()->create();

    expect($user->routeNotificationForNtfy())->toBe('my-topic')
        ->and($bare->routeNotificationForNtfy())->toBeNull();
});
