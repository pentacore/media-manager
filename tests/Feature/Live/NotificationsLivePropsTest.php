<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Cache::flush();
});

test('the inbox props are live on the signed-in user channel', function (): void {
    $user = User::factory()->create();

    $page = $this->actingAs($user)
        ->get(route('notifications.index', absolute: false), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request())])
        ->assertOk()
        ->json();

    expect($page['liveProps'])->toHaveCount(2);

    foreach (['notifications', 'unreadCount'] as $prop) {
        expect($page['liveProps'][$prop]['listeners'])->toBe([[
            'channel' => ['name' => 'App.Models.User.'.$user->id, 'type' => 'private'],
            'events' => ['UserNotificationStored'],
        ]]);
    }
});
