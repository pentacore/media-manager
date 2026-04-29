<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use App\Models\ServiceMetric;
use App\Services\ServiceMetrics\ServiceMetricsRepository;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->sonarr()->create();
    $this->repo = resolve(ServiceMetricsRepository::class);
});

test('last60Minutes returns 60 buckets, gap-filled when there is no data', function (): void {
    $buckets = $this->repo->last60Minutes($this->connection->id);

    expect($buckets)->toHaveCount(60);
    expect($buckets[0]['status'])->toBe('gap');
    expect($buckets[0]['latency_ms'])->toBeNull();
});

test('last60Minutes places samples in the correct minute bucket', function (): void {
    $now = CarbonImmutable::now();

    ServiceMetric::factory()->create([
        'service_connection_id' => $this->connection->id,
        'status' => HealthStatus::Healthy,
        'latency_ms' => 50,
        // 10 minutes ago → index 50 in a 60-minute window starting at -60.
        'recorded_at' => $now->subMinutes(10),
    ]);

    $buckets = $this->repo->last60Minutes($this->connection->id, $now);

    $populated = collect($buckets)->filter(
        fn (array $b): bool => $b['status'] !== 'gap',
    );

    expect($populated)->toHaveCount(1);
    expect($populated->first()['status'])->toBe('healthy');
    expect($populated->first()['latency_ms'])->toBe(50);
});

test('last60Minutes prefers the worst observed status within a minute', function (): void {
    // Pin the clock at a minute boundary so both samples land in the
    // same bucket regardless of when the suite runs.
    $now = CarbonImmutable::now()->startOfMinute();
    $bucketTime = $now->subMinutes(5);

    ServiceMetric::factory()->create([
        'service_connection_id' => $this->connection->id,
        'status' => HealthStatus::Healthy,
        'latency_ms' => 30,
        'recorded_at' => $bucketTime,
    ]);
    ServiceMetric::factory()->create([
        'service_connection_id' => $this->connection->id,
        'status' => HealthStatus::Unhealthy,
        'latency_ms' => 200,
        'recorded_at' => $bucketTime->addSeconds(20),
    ]);

    $buckets = $this->repo->last60Minutes($this->connection->id, $now);

    $populated = collect($buckets)->filter(
        fn (array $b): bool => $b['status'] !== 'gap',
    );

    expect($populated->first()['status'])->toBe('unhealthy');
    // Average of 30 + 200 = 115.
    expect($populated->first()['latency_ms'])->toBe(115);
});

test('recentLatencySamples returns oldest-first integers', function (): void {
    foreach ([100, 110, 120, 130] as $latency) {
        ServiceMetric::factory()->create([
            'service_connection_id' => $this->connection->id,
            'latency_ms' => $latency,
            'recorded_at' => CarbonImmutable::now()->subSeconds(140 - $latency),
        ]);
    }

    expect($this->repo->recentLatencySamples($this->connection->id))->toBe([
        100,
        110,
        120,
        130,
    ]);
});

test('uptimePercent returns null when there are no samples', function (): void {
    expect($this->repo->uptimePercent($this->connection->id))->toBeNull();
});

test('uptimePercent rounds to two decimals', function (): void {
    ServiceMetric::factory()->count(3)->create([
        'service_connection_id' => $this->connection->id,
        'status' => HealthStatus::Healthy,
        'recorded_at' => CarbonImmutable::now()->subDays(1),
    ]);
    ServiceMetric::factory()->create([
        'service_connection_id' => $this->connection->id,
        'status' => HealthStatus::Unhealthy,
        'recorded_at' => CarbonImmutable::now()->subDays(1),
    ]);

    // 3 healthy / 4 total = 75%
    expect($this->repo->uptimePercent($this->connection->id))->toBe(75.0);
});

test('averageLatencyMs ignores rows without latency', function (): void {
    ServiceMetric::factory()->create([
        'service_connection_id' => $this->connection->id,
        'latency_ms' => 200,
        'recorded_at' => CarbonImmutable::now()->subMinutes(1),
    ]);
    ServiceMetric::factory()->create([
        'service_connection_id' => $this->connection->id,
        'latency_ms' => null,
        'recorded_at' => CarbonImmutable::now()->subMinutes(1),
    ]);

    expect($this->repo->averageLatencyMs($this->connection->id))->toBe(200);
});

test('last60MinutesFor returns one entry per requested id', function (): void {
    $other = ServiceConnection::factory()->radarr()->create();

    ServiceMetric::factory()->create([
        'service_connection_id' => $this->connection->id,
        'recorded_at' => CarbonImmutable::now()->subMinutes(2),
    ]);

    $strips = $this->repo->last60MinutesFor([$this->connection->id, $other->id]);

    expect($strips)->toHaveKeys([$this->connection->id, $other->id]);
    expect($strips[$this->connection->id])->toHaveCount(60);
    expect($strips[$other->id])->toHaveCount(60);
});
