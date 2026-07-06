<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Models\EmbyActivity;
use App\Models\ServiceMetric;
use App\Models\StatRollup;
use App\Services\Statistics\StatisticsAggregator;
use App\Settings\AppSettings;
use Carbon\CarbonImmutable;

it('rolls up watch plays per hour and day', function (): void {
    CarbonImmutable::setTestNow('2026-07-06 15:00:00');
    EmbyActivity::factory()->count(3)->create(['action' => 'played', 'media_type' => 'Movie', 'created_at' => '2026-07-06 14:10:00']);
    EmbyActivity::factory()->create(['action' => 'played', 'media_type' => 'Episode', 'created_at' => '2026-07-06 14:20:00']);

    resolve(StatisticsAggregator::class)->aggregate(
        CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC'),
        CarbonImmutable::parse('2026-07-06 15:00:00', 'UTC'),
    );

    $movieHour = StatRollup::query()
        ->where(['metric' => 'watch.plays', 'period' => 'hour'])
        ->where('dimensions->media_type', 'Movie')
        ->sole();

    expect($movieHour->count)->toBe(3)
        ->and(StatRollup::query()->where(['metric' => 'watch.plays', 'period' => 'day'])->count())->toBe(2);
});

it('is idempotent across reruns', function (): void {
    EmbyActivity::factory()->count(2)->create(['action' => 'played', 'media_type' => 'Movie', 'created_at' => '2026-07-06 14:10:00']);
    $window = [CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC'), CarbonImmutable::parse('2026-07-06 15:00:00', 'UTC')];

    resolve(StatisticsAggregator::class)->aggregate(...$window);
    resolve(StatisticsAggregator::class)->aggregate(...$window);

    expect(StatRollup::query()->where(['metric' => 'watch.plays', 'period' => 'hour'])->sole()->count)->toBe(2);
});

it('advances the watermark only on success and resumes from it', function (): void {
    CarbonImmutable::setTestNow('2026-07-06 16:30:00');

    resolve(StatisticsAggregator::class)->aggregate();

    expect(resolve(AppSettings::class)->get('statistics.aggregate_watermark'))
        ->toBe(CarbonImmutable::parse('2026-07-06 16:00:00', 'UTC')->toIso8601String());
});

it('computes uptime and latency stats from service metrics', function (): void {
    $metric = ServiceMetric::factory()->create(['status' => HealthStatus::Healthy, 'latency_ms' => 100, 'recorded_at' => '2026-07-06 14:05:00']);
    ServiceMetric::factory()->create([
        'service_connection_id' => $metric->service_connection_id,
        'status' => HealthStatus::Unhealthy, 'latency_ms' => 300, 'recorded_at' => '2026-07-06 14:10:00',
    ]);

    resolve(StatisticsAggregator::class)->aggregate(
        CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC'),
        CarbonImmutable::parse('2026-07-06 15:00:00', 'UTC'),
    );

    $uptime = StatRollup::query()->where(['metric' => 'service.uptime_pct', 'period' => 'hour'])->sole();
    $latency = StatRollup::query()->where(['metric' => 'service.latency_ms', 'period' => 'hour'])->sole();

    expect($uptime->count)->toBe(2)->and($uptime->sum)->toBe(1.0)
        ->and($latency->min)->toBe(100.0)->and($latency->max)->toBe(300.0);
});
