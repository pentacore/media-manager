<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Events\ServiceHealthChanged;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use App\Models\ServiceMetric;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
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
        'health_message' => 'previously failed',
    ]);

    Http::fake(['sonarr.local:8989/api/v3/system/status' => Http::response(['version' => '4.0.0'])]);

    new PingServiceHealth($connection)->handle();

    $fresh = $connection->fresh();
    expect($fresh->health_status)->toBe(HealthStatus::Healthy);
    expect($fresh->health_message)->toBeNull();
    expect($fresh->version)->toBe('4.0.0');
    expect($fresh->last_seen_at)->not->toBeNull();

    Event::assertDispatched(ServiceHealthChanged::class);
});

test('marks unhealthy on request failure and records HTTP status with body snippet', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(['radarr.local:7878/*' => Http::response('<html><body>502 Bad Gateway</body></html>', 502)]);

    new PingServiceHealth($connection)->handle();

    $fresh = $connection->fresh();
    expect($fresh->health_status)->toBe(HealthStatus::Unhealthy);
    expect($fresh->health_message)->toStartWith('HTTP 502');
    expect($fresh->health_message)->toContain('502 Bad Gateway');
    Event::assertDispatched(ServiceHealthChanged::class);
});

test('records connection-level failures with a connection prefix', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    new PingServiceHealth($connection)->handle();

    $fresh = $connection->fresh();
    expect($fresh->health_status)->toBe(HealthStatus::Unhealthy);
    expect($fresh->health_message)->toStartWith('Connection failed:');
    expect($fresh->health_message)->toContain('Failed to connect');
});

test('truncates very long upstream bodies to 255 chars', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake(['sonarr.local:8989/*' => Http::response(str_repeat('A', 5000), 500)]);

    new PingServiceHealth($connection)->handle();

    expect(strlen((string) $connection->fresh()->health_message))->toBeLessThanOrEqual(255);
});

test('broadcasts on every successful ping so last_seen_at heartbeat propagates', function (): void {
    // Even with no status flip, a successful ping refreshes last_seen_at, which
    // is a UI-relevant change worth broadcasting to subscribers.
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => HealthStatus::Healthy,
        'last_seen_at' => now()->subHour(),
        'version' => '4.0.0',
    ]);

    Http::fake(['sonarr.local:8989/api/v3/system/status' => Http::response(['version' => '4.0.0'])]);

    new PingServiceHealth($connection)->handle();

    Event::assertDispatched(ServiceHealthChanged::class);
});

test('broadcasts when health_message changes even if status stays unhealthy', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => HealthStatus::Unhealthy,
        'health_message' => 'HTTP 500: previous failure',
    ]);

    Http::fake(['sonarr.local:8989/*' => Http::response('different body', 502)]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_message)->toStartWith('HTTP 502');
    Event::assertDispatched(ServiceHealthChanged::class);
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

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->version)->toBe('4.8.0.15');
});

test('handles seerr via getStatus', function (): void {
    $connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
    ]);

    Http::fake(['seerr.local:5055/api/v1/status' => Http::response(['version' => '3.0.0'])]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->version)->toBe('3.0.0');
});

test('writes a ServiceMetric row on success', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake(['sonarr.local:8989/api/v3/system/status' => Http::response(['version' => '4.0.0'])]);

    new PingServiceHealth($connection)->handle();

    $metric = ServiceMetric::query()
        ->where('service_connection_id', $connection->id)
        ->latest('id')
        ->first();

    expect($metric)->not->toBeNull();
    expect($metric->status)->toBe(HealthStatus::Healthy);
    expect($metric->message)->toBeNull();
    expect($metric->latency_ms)->toBeGreaterThanOrEqual(0);
});

test('writes a ServiceMetric row on connection failure with null latency', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
    ]);

    Http::fake(function (): void {
        throw new ConnectionException('Connection refused');
    });

    new PingServiceHealth($connection)->handle();

    $metric = ServiceMetric::query()
        ->where('service_connection_id', $connection->id)
        ->latest('id')
        ->first();

    expect($metric)->not->toBeNull();
    expect($metric->status)->toBe(HealthStatus::Unhealthy);
    expect($metric->latency_ms)->toBeNull();
    expect($metric->message)->toContain('Connection');
});
