<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Events\ServiceHealthChanged;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Event::fake([ServiceHealthChanged::class]);
});

test('marks healthy and updates version on success', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => null,
    ]);

    Http::fake(['sonarr.local:8989/api/v3/system/status' => Http::response(['version' => '4.0.0'])]);

    (new PingServiceHealth($connection))->handle();

    $fresh = $connection->fresh();
    expect($fresh->health_status)->toBe(HealthStatus::Healthy);
    expect($fresh->version)->toBe('4.0.0');
    expect($fresh->last_seen_at)->not->toBeNull();

    Event::assertDispatched(ServiceHealthChanged::class);
});

test('marks unhealthy on request failure', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(['radarr.local:7878/*' => Http::response('boom', 500)]);

    (new PingServiceHealth($connection))->handle();

    expect($connection->fresh()->health_status)->toBe(HealthStatus::Unhealthy);
    Event::assertDispatched(ServiceHealthChanged::class);
});

test('does not broadcast when status is unchanged', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(['sonarr.local:8989/api/v3/system/status' => Http::response(['version' => '4.0.0'])]);

    (new PingServiceHealth($connection))->handle();

    Event::assertNotDispatched(ServiceHealthChanged::class);
});

test('implements ShouldQueue', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    expect(new PingServiceHealth($connection))->toBeInstanceOf(ShouldQueue::class);
});

test('handles emby via getSystemInfo', function (): void {
    $connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
    ]);

    Http::fake(['emby.local:8096/System/Info' => Http::response(['Version' => '4.8.0.15'])]);

    (new PingServiceHealth($connection))->handle();

    expect($connection->fresh()->version)->toBe('4.8.0.15');
});

test('handles seerr via getStatus', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
    ]);

    Http::fake(['seerr.local:5055/api/v1/status' => Http::response(['version' => '3.0.0'])]);

    (new PingServiceHealth($connection))->handle();

    expect($connection->fresh()->version)->toBe('3.0.0');
});
