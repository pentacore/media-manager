<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Events\ServiceHealthChanged;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use App\Models\ServiceMetric;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::flush();
    Event::fake([ServiceHealthChanged::class]);
});

test('marks Bazarr healthy and stores its reported version', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.local:6767',
        'api_key' => 'bazarr-secret',
        'health_status' => null,
        'health_message' => 'previously failed',
    ]);

    Http::fake([
        'bazarr.local:6767/api/system/status' => Http::response([
            'data' => ['bazarr_version' => '1.6.0'],
        ]),
    ]);

    new PingServiceHealth($connection)->handle();

    $fresh = $connection->fresh();

    expect($fresh->health_status)->toBe(HealthStatus::Healthy)
        ->and($fresh->health_message)->toBeNull()
        ->and($fresh->version)->toBe('1.6.0')
        ->and($fresh->last_seen_at)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://bazarr.local:6767/api/system/status'
            && $request->hasHeader('X-API-KEY', 'bazarr-secret');
    });
    Http::assertSentCount(1);
});

test('Bazarr health checks bypass cached system status', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.local:6767',
        'api_key' => 'bazarr-secret',
    ]);

    Http::fake([
        'bazarr.local:6767/api/system/status' => Http::sequence()
            ->push(['data' => ['bazarr_version' => '1.6.0']])
            ->push(['data' => ['bazarr_version' => '1.6.1']]),
    ]);

    new PingServiceHealth($connection)->handle();
    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->version)->toBe('1.6.1');

    $authenticatedStatusRequests = Http::recorded(
        fn (Request $request): bool => $request->url() === 'http://bazarr.local:6767/api/system/status'
            && $request->hasHeader('X-API-KEY', 'bazarr-secret'),
    );

    expect($authenticatedStatusRequests)->toHaveCount(2);
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

test('redacts url query strings from persisted failure messages', function (): void {
    $connection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'health_status' => HealthStatus::Healthy,
    ]);

    // Guzzle ConnectException messages include the full effective URI — for
    // SABnzbd that query string carries the mandatory apikey credential.
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out for http://sab.local:8080/api?output=json&apikey=supersecret&mode=version',
    ));

    new PingServiceHealth($connection)->handle();

    $fresh = $connection->fresh();
    expect($fresh->health_status)->toBe(HealthStatus::Unhealthy);
    expect($fresh->health_message)->toStartWith('Connection failed:');
    expect($fresh->health_message)->not->toContain('supersecret');
    expect($fresh->health_message)->toContain('http://sab.local:8080/api?[redacted]');
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

test('handles sabnzbd via getVersion', function (): void {
    $connection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
    ]);

    Http::fake(['sab.local:8080/api*' => Http::response(['version' => '4.2.0'])]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->version)->toBe('4.2.0');
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
