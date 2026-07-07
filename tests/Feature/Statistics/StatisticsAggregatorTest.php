<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Models\ActionRequest;
use App\Models\AgentDecision;
use App\Models\AiUsageRecord;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
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

    $statRollup = StatRollup::query()
        ->where(['metric' => 'watch.plays', 'period' => 'hour'])
        ->where('dimensions->media_type', 'Movie')
        ->sole();

    expect($statRollup->count)->toBe(3)
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

it('sums all five token columns for the ai.tokens rollup', function (): void {
    AiUsageRecord::query()->insert([
        'invocation_id' => 'inv-tokens',
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'cache_read_input_tokens' => 20,
        'cache_write_input_tokens' => 10,
        'reasoning_tokens' => 5,
        'tool_calls_count' => 0,
        'status' => 'success',
        'created_at' => '2026-07-06 14:15:00',
        'updated_at' => '2026-07-06 14:15:00',
    ]);

    resolve(StatisticsAggregator::class)->aggregate(
        CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC'),
        CarbonImmutable::parse('2026-07-06 15:00:00', 'UTC'),
    );

    $statRollup = StatRollup::query()
        ->where(['metric' => 'ai.tokens', 'period' => 'hour'])
        ->sole();

    expect($statRollup->sum)->toBe(100.0 + 50.0 + 20.0 + 10.0 + 5.0);
});

it('rolls up actions by status with a system origin fallback', function (): void {
    CarbonImmutable::setTestNow('2026-07-06 15:00:00');
    // Mixed status/type/origin, plus one row that leaves origin at its 'system'
    // default so the COALESCE(origin, 'system') dimension is exercised.
    ActionRequest::factory()->create(['status' => 'completed', 'type' => 'delete_series', 'origin' => 'agent', 'created_at' => '2026-07-06 14:10:00']);
    ActionRequest::factory()->count(2)->create(['status' => 'pending', 'type' => 'delete_movie', 'origin' => 'user', 'created_at' => '2026-07-06 14:20:00']);
    ActionRequest::factory()->create(['status' => 'failed', 'type' => 'delete_series', 'origin' => 'system', 'created_at' => '2026-07-06 14:30:00']);

    resolve(StatisticsAggregator::class)->aggregate(
        CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC'),
        CarbonImmutable::parse('2026-07-06 15:00:00', 'UTC'),
    );

    $rows = StatRollup::query()->where(['metric' => 'actions.by_status', 'period' => 'hour'])->get();

    expect($rows->sum('count'))->toBe(4)
        ->and($rows->firstWhere('dimensions.status', 'pending')->count)->toBe(2)
        // Null origin COALESCEs to 'system'.
        ->and($rows->firstWhere('dimensions.status', 'failed')->dimensions['origin'])->toBe('system')
        ->and($rows->firstWhere('dimensions.status', 'completed')->dimensions['origin'])->toBe('agent');
});

it('rolls up agent decisions with summed action counts', function (): void {
    CarbonImmutable::setTestNow('2026-07-06 15:00:00');
    AgentDecision::factory()->completed(3)->create(['service' => 'sonarr', 'created_at' => '2026-07-06 14:10:00']);
    AgentDecision::factory()->completed(2)->create(['service' => 'sonarr', 'created_at' => '2026-07-06 14:20:00']);
    AgentDecision::factory()->create(['status' => 'no_action', 'service' => 'radarr', 'actions_count' => 0, 'created_at' => '2026-07-06 14:30:00']);

    resolve(StatisticsAggregator::class)->aggregate(
        CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC'),
        CarbonImmutable::parse('2026-07-06 15:00:00', 'UTC'),
    );

    $completed = StatRollup::query()
        ->where(['metric' => 'agent.decisions', 'period' => 'hour'])
        ->where('dimensions->status', 'completed')
        ->where('dimensions->service', 'sonarr')
        ->sole();
    $noAction = StatRollup::query()
        ->where(['metric' => 'agent.decisions', 'period' => 'hour'])
        ->where('dimensions->status', 'no_action')
        ->sole();

    expect($completed->count)->toBe(2)
        ->and($completed->sum)->toBe(5.0)
        ->and($noAction->count)->toBe(1)
        ->and($noAction->dimensions['service'])->toBe('radarr');
});

it('rolls up library additions per kind and excludes null arr_added_at', function (): void {
    CarbonImmutable::setTestNow('2026-07-06 15:00:00');
    IndexedMovie::factory()->count(2)->create(['arr_added_at' => '2026-07-06 14:10:00']);
    IndexedSeries::factory()->create(['arr_added_at' => '2026-07-06 14:20:00']);
    // Never surfaced by arr (null arr_added_at) — excluded from the rollup.
    IndexedMovie::factory()->create(['arr_added_at' => null]);

    resolve(StatisticsAggregator::class)->aggregate(
        CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC'),
        CarbonImmutable::parse('2026-07-06 15:00:00', 'UTC'),
    );

    $movies = StatRollup::query()
        ->where(['metric' => 'library.added', 'period' => 'hour'])
        ->where('dimensions->kind', 'movie')
        ->sole();
    $series = StatRollup::query()
        ->where(['metric' => 'library.added', 'period' => 'hour'])
        ->where('dimensions->kind', 'series')
        ->sole();

    expect($movies->count)->toBe(2)
        ->and($series->count)->toBe(1);
});

it('sums watched seconds from stopped and finished ticks per user', function (): void {
    CarbonImmutable::setTestNow('2026-07-06 15:00:00');
    $link = EmbyUserLink::factory()->create();
    // 1e7 ticks = 1s: 3e8 ticks = 30s, 1.5e8 ticks = 15s => 45s.
    EmbyActivity::factory()->create(['emby_user_link_id' => $link->id, 'action' => 'stopped', 'play_position' => 300_000_000, 'created_at' => '2026-07-06 14:10:00']);
    EmbyActivity::factory()->create(['emby_user_link_id' => $link->id, 'action' => 'finished', 'play_position' => 150_000_000, 'created_at' => '2026-07-06 14:20:00']);
    // A played action is not a watch-seconds event and must be excluded.
    EmbyActivity::factory()->create(['emby_user_link_id' => $link->id, 'action' => 'played', 'play_position' => 999_000_000, 'created_at' => '2026-07-06 14:30:00']);

    resolve(StatisticsAggregator::class)->aggregate(
        CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC'),
        CarbonImmutable::parse('2026-07-06 15:00:00', 'UTC'),
    );

    $statRollup = StatRollup::query()
        ->where(['metric' => 'watch.seconds', 'period' => 'hour'])
        ->where('dimensions->emby_user_link_id', (string) $link->id)
        ->sole();

    expect($statRollup->count)->toBe(2)
        ->and($statRollup->sum)->toBe(45.0);
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

    $statRollup = StatRollup::query()->where(['metric' => 'service.uptime_pct', 'period' => 'hour'])->sole();
    $latency = StatRollup::query()->where(['metric' => 'service.latency_ms', 'period' => 'hour'])->sole();

    expect($statRollup->count)->toBe(2)->and($statRollup->sum)->toBe(1.0)
        ->and($latency->min)->toBe(100.0)->and($latency->max)->toBe(300.0);
});
