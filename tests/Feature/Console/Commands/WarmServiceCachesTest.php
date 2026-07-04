<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Support\Presence\PresenceTracker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('mediamanager.presence.key', 'presence:users:test-'.getmypid());
    config()->set('mediamanager.presence.heartbeat_ttl', 90);
    Redis::connection()->del(config('mediamanager.presence.key'));
});

afterEach(function (): void {
    Redis::connection()->del(config('mediamanager.presence.key'));
});

test('command bails immediately when no users are active', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    $this->artisan('services:warm-caches')
        ->expectsOutputToContain('No active users')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('command warms an active Sonarr connection when a user is present', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'title' => 'Severance'],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([
            ['id' => 1, 'name' => 'HD-1080p'],
        ]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([
            ['id' => 1, 'path' => '/tv'],
        ]),
    ]);

    resolve(PresenceTracker::class)->markActive('user-1');

    $this->artisan('services:warm-caches')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v3/series'));
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v3/qualityprofile'));
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v3/rootfolder'));
});

test('warming skips inactive connections', function (): void {
    ServiceConnection::factory()->sonarr()->inactive()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    resolve(PresenceTracker::class)->markActive('user-1');

    $this->artisan('services:warm-caches')->assertSuccessful();

    Http::assertNothingSent();
});

test('warming skips non-warmable service types like Emby', function (): void {
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);

    resolve(PresenceTracker::class)->markActive('user-1');

    $this->artisan('services:warm-caches')->assertSuccessful();

    Http::assertNothingSent();
});

test('an upstream failure on one connection is logged but does not abort the run', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://broken-sonarr.local:8989',
        'api_key' => 'k',
        'name' => 'Broken Sonarr',
    ]);
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);

    Http::fake([
        'broken-sonarr.local:8989/*' => Http::response('boom', 500),
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 1, 'title' => 'Dune'],
        ]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([]),
        'radarr.local:7878/api/v3/rootfolder' => Http::response([]),
    ]);

    Log::spy();

    resolve(PresenceTracker::class)->markActive('user-1');

    $this->artisan('services:warm-caches')->assertSuccessful();

    Log::shouldHaveReceived('warning')
        ->with('cache warm failed', Mockery::on(fn (array $context): bool => ($context['service'] ?? null) === 'sonarr'))
        ->atLeast()
        ->once();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'radarr.local:7878/api/v3/movie'));
});
