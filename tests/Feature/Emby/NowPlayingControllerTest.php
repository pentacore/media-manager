<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'test-api-key',
    ]);
});

test('guests are redirected to login from now playing', function (): void {
    $this->get(route('monitoring.now-playing'))->assertRedirect(route('login'));
});

test('authenticated users can see now playing sessions', function (): void {
    $user = User::factory()->create();

    Http::fake([
        'emby.local:8096/Sessions' => Http::response([
            [
                'Id' => 'session-1',
                'UserName' => 'alice',
                'Client' => 'Emby Web',
                'DeviceName' => 'Chrome',
                'NowPlayingItem' => [
                    'Id' => 'item-1',
                    'Name' => 'Pilot',
                    'Type' => 'Episode',
                    'SeriesName' => 'My Show',
                    'RunTimeTicks' => 12000000000,
                ],
                'PlayState' => ['PositionTicks' => 5000000000, 'IsPaused' => false],
            ],
            [
                'Id' => 'session-2',
                'UserName' => 'bob',
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.now-playing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Emby/NowPlaying')
            ->has('sessions', 1)
            ->where('sessions.0.user_name', 'alice')
            ->where('sessions.0.now_playing.name', 'Pilot')
        );
});

test('now playing redirects when no active emby connection', function (): void {
    $this->connection->update(['is_active' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('monitoring.now-playing'))
        ->assertRedirect(route('dashboard'));
});

test('now playing handles connection failure gracefully', function (): void {
    $user = User::factory()->create();

    Http::fake(fn () => Http::response('Service Unavailable', 503));

    $this->actingAs($user)
        ->get(route('monitoring.now-playing'))
        ->assertRedirect(route('dashboard'));
});
