<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use App\Models\StatRollup;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config()->set('mediamanager.metrics.token', 'test-token');
});

it('rejects requests without the token', function (): void {
    $this->get('/metrics')->assertForbidden();
});

it('rejects requests with an incorrect token', function (): void {
    $this->withHeader('Authorization', 'Bearer wrong-token')
        ->get('/metrics')
        ->assertForbidden();
});

it('denies all access when no token is configured', function (): void {
    config()->set('mediamanager.metrics.token');

    $this->withHeader('Authorization', 'Bearer test-token')
        ->get('/metrics')
        ->assertForbidden();
});

it('serves prometheus metrics with a valid bearer token', function (): void {
    ServiceConnection::factory()->create(['is_active' => true, 'health_status' => HealthStatus::Healthy]);

    $this->withHeader('Authorization', 'Bearer test-token')
        ->get('/metrics')
        ->assertOk()
        ->assertSee('mediamanager_service_up');
});

it('rejects an array token parameter with a 403 instead of erroring', function (): void {
    $this->get('/metrics?token[]=test-token')->assertForbidden();
});

it('accepts the token via query string', function (): void {
    ServiceConnection::factory()->create(['is_active' => true, 'health_status' => HealthStatus::Healthy]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_service_up');
});

it('exports the rollup-backed activity gauges', function (): void {
    // The "today" gauges resolve to the hour period (a sub-day window), so the
    // gauge sums the current day's hour buckets — matching what the aggregator
    // writes at runtime.
    StatRollup::factory()->hour()->create([
        'metric' => 'webhooks.received',
        'period' => 'hour',
        'bucket' => CarbonImmutable::now('UTC')->startOfHour(),
        'dimensions' => ['service' => 'sonarr'],
        'count' => 12,
    ]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_webhooks_received_today{service="sonarr"} 12', escape: false)
        ->assertSee('mediamanager_pending_actions');
});

it('exports the newest hour-bucket sample as the mean of the window', function (): void {
    // Two accumulated samples in the same hour bucket: sum 300, count 2 -> mean 150.
    StatRollup::factory()->create([
        'metric' => 'service.disk_free_bytes',
        'period' => 'hour',
        'bucket' => CarbonImmutable::now('UTC')->startOfHour(),
        'dimensions' => ['connection' => '7', 'path' => '/data'],
        'count' => 2,
        'sum' => 300.0,
    ]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_disk_free_bytes{connection="7",path="/data"} 150', escape: false);
});

it('drops latest-sample series whose newest bucket is stale', function (): void {
    // A dead or deleted connection must go absent from /metrics rather than
    // exporting its frozen last value for the whole hour retention.
    StatRollup::factory()->create([
        'metric' => 'queue.depth',
        'period' => 'hour',
        'bucket' => CarbonImmutable::now('UTC')->subHours(3)->startOfHour(),
        'dimensions' => ['connection' => '9', 'service' => 'sabnzbd'],
        'count' => 1,
        'sum' => 40.0,
    ]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertDontSee('mediamanager_queue_depth{service="sabnzbd"}', escape: false);
});
