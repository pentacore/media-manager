<?php

declare(strict_types=1);

use App\Enums\TimeWindow;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\StatRollup;
use App\Models\User;
use App\Services\Statistics\StatisticsRepository;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->repo = resolve(StatisticsRepository::class);
    CarbonImmutable::setTestNow('2026-07-06 12:00:00');
});

it('returns gap-padded day series for a 30d window', function (): void {
    StatRollup::factory()->create([
        'metric' => 'downloads.completed', 'period' => 'day',
        'bucket' => CarbonImmutable::now('UTC')->subDays(3)->startOfDay(), 'count' => 5,
    ]);

    $series = $this->repo->series('downloads.completed', TimeWindow::Last30d);

    expect($series)->toHaveCount(31)
        ->and(collect($series)->sum('count'))->toBe(5)
        ->and(collect($series)->firstWhere('count', 5)['bucket'])->toContain('2026-07-03');
});

it('returns gap-padded hour series for a 7d window', function (): void {
    StatRollup::factory()->hour()->create([
        'metric' => 'downloads.completed', 'period' => 'hour',
        'bucket' => CarbonImmutable::now('UTC')->subHours(2)->startOfHour(), 'count' => 7,
    ]);

    $series = $this->repo->series('downloads.completed', TimeWindow::Last7d);

    // 7 days * 24 hours + the current hour bucket = 169 buckets.
    expect($series)->toHaveCount(24 * 7 + 1)
        ->and(collect($series)->sum('count'))->toBe(7);
});

it('clamps the unbounded All window to the earliest rollup instead of the epoch', function (): void {
    StatRollup::factory()->create([
        'metric' => 'downloads.completed', 'period' => 'day',
        'bucket' => CarbonImmutable::now('UTC')->subDays(10)->startOfDay(), 'count' => 5,
    ]);

    $series = $this->repo->series('downloads.completed', TimeWindow::All);

    // 10 past days + today — not ~20k day buckets padded from 1970.
    expect($series)->toHaveCount(11)
        ->and(collect($series)->sum('count'))->toBe(5);
});

it('returns a single empty bucket for the All window when no rollups exist', function (): void {
    expect($this->repo->series('downloads.completed', TimeWindow::All))->toHaveCount(1);
});

it('forces the day period for metrics recorded only as daily snapshots', function (): void {
    // library.* is written by the daily snapshot as day rows only; a ≤7d
    // window resolves to 'hour' and would read an all-zero series.
    StatRollup::factory()->create([
        'metric' => 'library.movies', 'period' => 'day',
        'bucket' => CarbonImmutable::now('UTC')->subDay()->startOfDay(), 'count' => 420,
    ]);

    $hourPeriod = $this->repo->series('library.movies', TimeWindow::Last7d);
    $forcedDay = $this->repo->series('library.movies', TimeWindow::Last7d, [], 'day');

    expect(collect($hourPeriod)->sum('count'))->toBe(0)
        ->and(collect($forcedDay)->sum('count'))->toBe(420);
});

it('respects the dimension filter in a series', function (): void {
    $bucket = CarbonImmutable::now('UTC')->subDays(2)->startOfDay();
    StatRollup::factory()->create(['metric' => 'webhooks.received', 'period' => 'day', 'bucket' => $bucket, 'dimensions' => ['service' => 'sonarr'], 'count' => 3]);
    StatRollup::factory()->create(['metric' => 'webhooks.received', 'period' => 'day', 'bucket' => $bucket, 'dimensions' => ['service' => 'radarr'], 'count' => 9]);

    $series = $this->repo->series('webhooks.received', TimeWindow::Last30d, ['service' => 'sonarr']);

    expect(collect($series)->sum('count'))->toBe(3);
});

it('sums the numeric sum column in a series', function (): void {
    StatRollup::factory()->create([
        'metric' => 'watch.seconds', 'period' => 'day',
        'bucket' => CarbonImmutable::now('UTC')->subDays(1)->startOfDay(),
        'dimensions' => ['emby_user_link_id' => 1], 'count' => 2, 'sum' => 123.5,
    ]);

    $series = $this->repo->series('watch.seconds', TimeWindow::Last30d);

    expect(collect($series)->sum('sum'))->toBe(123.5);
});

it('sums dimension-filtered totals', function (): void {
    StatRollup::factory()->create(['metric' => 'webhooks.received', 'period' => 'day', 'dimensions' => ['event_type' => 'Grab', 'service' => 'sonarr'], 'count' => 4]);
    StatRollup::factory()->create(['metric' => 'webhooks.received', 'period' => 'day', 'dimensions' => ['event_type' => 'Grab', 'service' => 'radarr'], 'count' => 2]);

    expect($this->repo->total('webhooks.received', TimeWindow::Last30d, ['service' => 'sonarr'])['count'])->toBe(4)
        ->and($this->repo->total('webhooks.received', TimeWindow::Last30d)['count'])->toBe(6);
});

it('counts a sub-day window from hour rows so it does not undercount', function (): void {
    // now-20h is yesterday's day bucket but still within the last 24h. Reading
    // 'day' rows keyed on the now-24h cutoff would exclude yesterday's whole-day
    // bucket, so a 24h window would only show today-so-far. total() must read the
    // 'hour' rows (the period series() picks for a ≤7d cutoff) instead.
    $hourBucket = CarbonImmutable::now('UTC')->subHours(20)->startOfHour();
    $dayBucket = CarbonImmutable::now('UTC')->subHours(20)->startOfDay();

    StatRollup::factory()->hour()->create(['metric' => 'webhooks.received', 'period' => 'hour', 'bucket' => $hourBucket, 'dimensions' => ['service' => 'sonarr'], 'count' => 6]);
    // Parallel day row for the same events — must NOT be summed in as well.
    StatRollup::factory()->create(['metric' => 'webhooks.received', 'period' => 'day', 'bucket' => $dayBucket, 'dimensions' => ['service' => 'sonarr'], 'count' => 6]);

    expect($this->repo->total('webhooks.received', TimeWindow::Last24h)['count'])->toBe(6);
});

it('reads day rows for a window beyond seven days', function (): void {
    // A >7d cutoff resolves to the 'day' period, so day rows are counted and the
    // parallel hour rows are ignored.
    $bucket = CarbonImmutable::now('UTC')->subDays(10)->startOfDay();

    StatRollup::factory()->create(['metric' => 'webhooks.received', 'period' => 'day', 'bucket' => $bucket, 'dimensions' => ['service' => 'sonarr'], 'count' => 9]);
    StatRollup::factory()->hour()->create(['metric' => 'webhooks.received', 'period' => 'hour', 'bucket' => $bucket->addHours(3), 'dimensions' => ['service' => 'sonarr'], 'count' => 9]);

    expect($this->repo->total('webhooks.received', TimeWindow::Last30d)['count'])->toBe(9);
});

it('does not double-count parallel hour and day rollups in totals', function (): void {
    // The aggregator writes both hour and day rollups for the same events; a
    // >7d window reads the day period, so it must ignore the parallel hour rows
    // and reflect real events once.
    StatRollup::factory()->create(['metric' => 'webhooks.received', 'period' => 'day', 'dimensions' => ['service' => 'sonarr'], 'count' => 8]);
    StatRollup::factory()->hour()->create(['metric' => 'webhooks.received', 'period' => 'hour', 'dimensions' => ['service' => 'sonarr'], 'count' => 8]);

    expect($this->repo->total('webhooks.received', TimeWindow::Last30d)['count'])->toBe(8);
});

it('does not double-count parallel rollups in a breakdown', function (): void {
    StatRollup::factory()->create(['metric' => 'actions.by_status', 'period' => 'day', 'dimensions' => ['status' => 'completed'], 'count' => 3]);
    StatRollup::factory()->hour()->create(['metric' => 'actions.by_status', 'period' => 'hour', 'dimensions' => ['status' => 'completed'], 'count' => 3]);

    $breakdown = $this->repo->breakdown('actions.by_status', TimeWindow::Last30d, 'status');

    expect($breakdown[0])->toBe(['key' => 'completed', 'count' => 3, 'sum' => 0.0]);
});

it('returns a float sum in totals', function (): void {
    StatRollup::factory()->create(['metric' => 'watch.seconds', 'period' => 'day', 'dimensions' => ['emby_user_link_id' => 1], 'count' => 1, 'sum' => 60.0]);
    StatRollup::factory()->create(['metric' => 'watch.seconds', 'period' => 'day', 'dimensions' => ['emby_user_link_id' => 2], 'count' => 1, 'sum' => 30.5]);

    $total = $this->repo->total('watch.seconds', TimeWindow::Last30d);

    expect($total['count'])->toBe(2)
        ->and($total['sum'])->toBe(90.5);
});

it('breaks a metric down by one dimension key', function (): void {
    StatRollup::factory()->create(['metric' => 'actions.by_status', 'period' => 'day', 'dimensions' => ['status' => 'completed', 'type' => 'x', 'origin' => 'agent'], 'count' => 3]);
    StatRollup::factory()->create(['metric' => 'actions.by_status', 'period' => 'day', 'dimensions' => ['status' => 'failed', 'type' => 'x', 'origin' => 'agent'], 'count' => 1]);

    $breakdown = $this->repo->breakdown('actions.by_status', TimeWindow::Last30d, 'status');

    expect($breakdown[0])->toBe(['key' => 'completed', 'count' => 3, 'sum' => 0.0]);
});

it('orders a breakdown by count descending', function (): void {
    StatRollup::factory()->create(['metric' => 'actions.by_status', 'period' => 'day', 'dimensions' => ['status' => 'completed'], 'count' => 2]);
    StatRollup::factory()->create(['metric' => 'actions.by_status', 'period' => 'day', 'dimensions' => ['status' => 'failed'], 'count' => 5]);

    $breakdown = $this->repo->breakdown('actions.by_status', TimeWindow::Last30d, 'status');

    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[0]['key'])->toBe('failed')
        ->and($breakdown[0]['count'])->toBe(5);
});

it('lists top played titles from live emby activity', function (): void {
    EmbyActivity::factory()->count(3)->create(['action' => 'played', 'media_title' => 'The Matrix', 'series_title' => null, 'media_type' => 'movie', 'created_at' => CarbonImmutable::now()->subDay()]);
    EmbyActivity::factory()->create(['action' => 'played', 'media_title' => 'Pilot', 'series_title' => 'Severance', 'media_type' => 'episode', 'created_at' => CarbonImmutable::now()->subDay()]);
    // Non-played actions are excluded.
    EmbyActivity::factory()->create(['action' => 'stopped', 'media_title' => 'The Matrix', 'media_type' => 'movie', 'created_at' => CarbonImmutable::now()->subDay()]);

    $titles = $this->repo->topTitles(TimeWindow::Last7d);

    expect($titles[0])->toBe(['title' => 'The Matrix', 'media_type' => 'movie', 'plays' => 3])
        ->and($titles[1]['title'])->toBe('Severance')
        ->and($titles[1]['plays'])->toBe(1);
});

it('honours the limit on top titles', function (): void {
    foreach (['A', 'B', 'C'] as $index => $title) {
        EmbyActivity::factory()->count($index + 1)->create(['action' => 'played', 'media_title' => $title, 'series_title' => null, 'media_type' => 'movie', 'created_at' => CarbonImmutable::now()->subDay()]);
    }

    expect($this->repo->topTitles(TimeWindow::Last7d, 2))->toHaveCount(2);
});

it('builds a watch leaderboard with display names', function (): void {
    $named = EmbyUserLink::factory()->for(User::factory()->state(['name' => 'Alice']))->create();
    $unlinked = EmbyUserLink::factory()->for(User::factory()->state(['name' => '']))->create(['emby_username' => 'bob_emby']);

    StatRollup::factory()->create(['metric' => 'watch.user_plays', 'period' => 'day', 'dimensions' => ['emby_user_link_id' => $named->id], 'count' => 10]);
    StatRollup::factory()->create(['metric' => 'watch.seconds', 'period' => 'day', 'dimensions' => ['emby_user_link_id' => $named->id], 'count' => 10, 'sum' => 3600.0]);
    StatRollup::factory()->create(['metric' => 'watch.user_plays', 'period' => 'day', 'dimensions' => ['emby_user_link_id' => $unlinked->id], 'count' => 4]);
    StatRollup::factory()->create(['metric' => 'watch.seconds', 'period' => 'day', 'dimensions' => ['emby_user_link_id' => $unlinked->id], 'count' => 4, 'sum' => 1200.0]);

    $board = $this->repo->watchLeaderboard(TimeWindow::Last30d);

    expect($board[0])->toBe(['user' => 'Alice', 'plays' => 10, 'seconds' => 3600.0])
        ->and($board[1])->toBe(['user' => 'bob_emby', 'plays' => 4, 'seconds' => 1200.0]);
});

it('builds a 7x24 watch heatmap in the app timezone', function (): void {
    // 2026-07-06 is a Monday (ISO weekday 1). 14:00 UTC == 14 in a UTC app.
    EmbyActivity::factory()->count(2)->create(['action' => 'played', 'created_at' => CarbonImmutable::parse('2026-07-06 14:30:00', 'UTC')]);
    EmbyActivity::factory()->create(['action' => 'stopped', 'created_at' => CarbonImmutable::parse('2026-07-06 14:30:00', 'UTC')]);

    $heatmap = $this->repo->watchHeatmap(TimeWindow::Last7d);

    expect($heatmap)->toHaveCount(7)
        ->and($heatmap[1])->toHaveCount(24)
        ->and($heatmap[1][14])->toBe(2)
        ->and($heatmap[1][13])->toBe(0)
        ->and($heatmap[2][14])->toBe(0);
});
