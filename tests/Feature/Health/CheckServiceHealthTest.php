<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Events\ServiceHealthChanged;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Event::fake([ServiceHealthChanged::class]);
});

test('marks sonarr connection Healthy and updates version on success', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => null,
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response(['version' => '4.0.0']),
    ]);

    $this->artisan('services:check-health')->assertSuccessful();

    $fresh = $connection->fresh();
    expect($fresh->health_status)->toBe(HealthStatus::Healthy);
    expect($fresh->version)->toBe('4.0.0');
    expect($fresh->last_seen_at)->not->toBeNull();

    Event::assertDispatched(ServiceHealthChanged::class);
});

test('marks emby connection Healthy using System/Info Version field', function (): void {
    $connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'health_status' => null,
    ]);

    Http::fake([
        'emby.local:8096/System/Info' => Http::response(['Version' => '4.8.0.15']),
    ]);

    $this->artisan('services:check-health')->assertSuccessful();

    expect($connection->fresh()->health_status)->toBe(HealthStatus::Healthy);
    expect($connection->fresh()->version)->toBe('4.8.0.15');
});

test('marks connection Unhealthy when request fails', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(['sonarr.local:8989/*' => Http::response('boom', 500)]);

    $this->artisan('services:check-health')->assertSuccessful();

    expect($connection->fresh()->health_status)->toBe(HealthStatus::Unhealthy);

    Event::assertDispatched(ServiceHealthChanged::class);
});

test('does not broadcast when status is unchanged', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(['sonarr.local:8989/api/v3/system/status' => Http::response(['version' => '4.0.0'])]);

    $this->artisan('services:check-health')->assertSuccessful();

    expect($connection->fresh()->health_status)->toBe(HealthStatus::Healthy);
    Event::assertNotDispatched(ServiceHealthChanged::class);
});

test('broadcasts on transition Healthy -> Unhealthy', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(['sonarr.local:8989/*' => Http::response('boom', 500)]);

    $this->artisan('services:check-health')->assertSuccessful();

    Event::assertDispatched(fn (ServiceHealthChanged $serviceHealthChanged): bool => $serviceHealthChanged->status === 'unhealthy');
});

test('skips inactive connections', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->inactive()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    // No Http::fake = would blow up if it tried to connect (preventStrayRequests)
    $this->artisan('services:check-health')->assertSuccessful();

    expect($connection->fresh()->health_status)->toBeNull();
});

test('iterates multiple connections', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    Http::fake([
        'sonarr.local:8989/*' => Http::response(['version' => '4.0.0']),
        'radarr.local:7878/*' => Http::response(['version' => '5.0.0']),
    ]);

    $this->artisan('services:check-health')->assertSuccessful();

    expect(ServiceConnection::pluck('health_status')->all())
        ->each->toBe(HealthStatus::Healthy);
});
