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
use Illuminate\Support\Sleep;

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

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://bazarr.local:6767/api/system/status'
        && $request->hasHeader('X-API-KEY', 'bazarr-secret'));
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
    Http::assertSentCount(2);
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

test('marks unhealthy on request failure and records the HTTP status with a fixed sentence', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(['radarr.local:7878/*' => Http::response('<html><body>502 Bad Gateway</body></html>', 502)]);

    new PingServiceHealth($connection)->handle();

    $fresh = $connection->fresh();
    expect($fresh->health_status)->toBe(HealthStatus::Unhealthy);
    expect($fresh->health_message)->toBe('HTTP 502: the service reported a server error.');
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

test('a 500 body is now irrelevant to length — the stored message is the fixed status sentence', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake(['sonarr.local:8989/*' => Http::response(str_repeat('A', 5000), 500)]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_message)->toBe('HTTP 500: the service reported a server error.');
});

test('truncates a very long connection-failure message to 255 chars', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake(fn () => throw new ConnectionException(str_repeat('A', 5000)));

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

test('a Sonarr answering its status with a login page is unhealthy, not healthy', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'health_status' => HealthStatus::Healthy]);
    Http::fake(['sonarr.local:8989/*' => Http::response('<html><body>Sign in</body></html>', 200, ['Content-Type' => 'text/html'])]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_status)->toBe(HealthStatus::Unhealthy)
        ->and($connection->fresh()->health_message)->toBe('HTTP 200: Sonarr answered with a body that is not JSON data.');
});

test('an Emby answering its system info with a login page is unhealthy, not healthy', function (): void {
    $connection = ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'health_status' => HealthStatus::Healthy]);
    Http::fake(['emby.local:8096/System/Info' => Http::response('<html><body>Sign in</body></html>', 200, ['Content-Type' => 'text/html'])]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_status)->toBe(HealthStatus::Unhealthy)
        ->and($connection->fresh()->health_message)->toBe('HTTP 200: Emby answered with a body that is not JSON data.');
});

test('a SABnzbd refusal is unhealthy, not healthy', function (): void {
    $connection = ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'health_status' => HealthStatus::Healthy]);
    Http::fake(['sab.local:8080/api*' => Http::response(['status' => false, 'error' => 'API Key Incorrect'], 200)]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_status)->toBe(HealthStatus::Unhealthy)
        ->and($connection->fresh()->health_message)->toBe('HTTP 200: SABnzbd refused the request.');
});

test('two different bodies at the same status store the same message and do not re-broadcast', function (): void {
    Sleep::fake();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'health_status' => HealthStatus::Unhealthy,
        'health_message' => 'HTTP 500: the service reported a server error.',
    ]);

    Http::fake(['sonarr.local:8989/*' => Http::response('A completely different error body', 500)]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_status)->toBe(HealthStatus::Unhealthy)
        ->and($connection->fresh()->health_message)->toBe('HTTP 500: the service reported a server error.');

    Event::assertNotDispatched(ServiceHealthChanged::class);
});

test('a stored and broadcast health message never carries an upstream path', function (): void {
    Sleep::fake();
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'health_status' => HealthStatus::Healthy]);
    Http::fake(['sonarr.local:8989/*' => Http::response('Database at /config/sonarr.db is locked', 500)]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_message)->toBe('HTTP 500: the service reported a server error.')
        ->and(ServiceMetric::query()->sole()->message)->toBe('HTTP 500: the service reported a server error.');
    Event::assertDispatched(fn (ServiceHealthChanged $serviceHealthChanged): bool => $serviceHealthChanged->broadcastWith()['message'] === 'HTTP 500: the service reported a server error.');
});

test('a connection failure naming a Windows path stores it redacted', function (): void {
    Sleep::fake();
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'health_status' => HealthStatus::Healthy]);
    Http::fake(['sonarr.local:8989/*' => fn () => throw new ConnectionException('Could not open C:\ProgramData\Sonarr\sonarr.db')]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_message)->toBe('Connection failed: Could not open [redacted path]');
});

test('an HTML error page is stored as a fixed sentence, not its text', function (): void {
    Sleep::fake();
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'health_status' => HealthStatus::Healthy]);
    Http::fake(['radarr.local:7878/*' => Http::response('<html><body><h1>502 Bad Gateway</h1></body></html>', 502)]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_message)->toBe('HTTP 502: the service reported a server error.');
});

test('a failed health ping stores one fixed sentence per kind of HTTP failure and never the body', function (int $status, string $body, string $expected): void {
    Sleep::fake();
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'health_status' => HealthStatus::Healthy]);
    Http::fake(['radarr.local:7878/*' => Http::response($body, $status)]);

    new PingServiceHealth($connection)->handle();

    expect($connection->fresh()->health_message)->toBe($expected)
        ->and(ServiceMetric::query()->sole()->message)->toBe($expected)
        ->and($connection->fresh()->health_message)->not->toContain('internal-host');
})->with([
    'unauthorized' => [401, 'Unauthorized for internal-host.lan', 'HTTP 401: the service rejected the API key.'],
    'forbidden' => [403, 'Forbidden on internal-host.lan', 'HTTP 403: the service rejected the API key.'],
    'not found' => [404, 'No route at internal-host.lan/api/v3/system/status', 'HTTP 404: the service refused the request.'],
    'server error' => [503, 'Upstream internal-host.lan:8080 is down', 'HTTP 503: the service reported a server error.'],
    'a 200 that is not JSON data' => [200, '<html>Sign in to internal-host.lan</html>', 'HTTP 200: Radarr answered with a body that is not JSON data.'],
]);
