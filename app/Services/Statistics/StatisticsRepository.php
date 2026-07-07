<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\TimeWindow;
use App\Models\EmbyUserLink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Read layer over `stat_rollups` (plus a couple of live `emby_activities`
 * queries for data we don't pre-aggregate). Every method takes a
 * {@see TimeWindow} and resolves its own cutoff so callers only pick a
 * named window. All bucket timestamps are emitted as ISO-8601 UTC so the
 * browser can localise them.
 */
class StatisticsRepository
{
    /**
     * Gap-padded time series for a metric. Buckets step by the resolved
     * period ('hour' for windows ≤ 7d, 'day' otherwise) from the window
     * start to now; empty buckets come back as `{count: 0, sum: null}`.
     * Pass $forcePeriod for metrics recorded at a single granularity
     * (daily put() snapshots like library.*) that have no hour rows.
     *
     * @param  array<string, scalar>  $dimensionFilter
     * @return list<array{bucket: string, count: int, sum: float|null}>
     */
    public function series(string $metric, TimeWindow $timeWindow, array $dimensionFilter = [], ?string $forcePeriod = null): array
    {
        $period = $forcePeriod ?? $this->periodFor($timeWindow);
        $since = $this->windowStart($timeWindow, $period);

        // The unbounded All window must not gap-pad from the epoch (~20k day
        // buckets); clamp its start to the earliest rollup we actually hold.
        if (! $timeWindow->cutoff() instanceof CarbonImmutable) {
            $earliest = DB::table('stat_rollups')
                ->where('metric', $metric)
                ->where('period', $period)
                ->min('bucket');

            $since = $earliest === null
                ? CarbonImmutable::now('UTC')->startOfDay()
                : CarbonImmutable::parse((string) $earliest, 'UTC');
        }

        $rows = $this->applyDimensionFilter(
            DB::table('stat_rollups')
                ->where('metric', $metric)
                ->where('period', $period)
                ->where('bucket', '>=', $since),
            $dimensionFilter,
        )
            ->selectRaw('bucket, SUM(count) AS count, SUM(sum) AS sum')
            ->groupBy('bucket')
            ->get()
            ->keyBy(fn (object $row): string => CarbonImmutable::parse((string) $row->bucket, 'UTC')->format('Y-m-d H:00'));

        $buckets = [];
        $cursor = $since;
        $now = CarbonImmutable::now('UTC');

        while ($cursor <= $now) {
            $row = $rows->get($cursor->format('Y-m-d H:00'));

            $buckets[] = [
                'bucket' => $cursor->toIso8601String(),
                'count' => (int) ($row->count ?? 0),
                'sum' => $row?->sum === null ? null : (float) $row->sum,
            ];

            $cursor = $period === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        return $buckets;
    }

    /**
     * Aggregate count + sum for a metric across the window, optionally
     * scoped to a dimension filter. Reads exactly one period — the same
     * pick {@see series()} uses ('hour' for windows ≤ 7d, 'day' otherwise).
     * The aggregator writes both 'hour' and 'day' rollups for the same
     * events, so reading one period reflects real events once; reading 'day'
     * only would drop a sub-day window's earlier hour buckets (a 24h window
     * whose cutoff excludes yesterday's day bucket would undercount).
     *
     * @param  array<string, scalar>  $dimensionFilter
     * @return array{count: int, sum: float}
     */
    public function total(string $metric, TimeWindow $timeWindow, array $dimensionFilter = []): array
    {
        $period = $this->periodFor($timeWindow);
        $since = $this->windowStart($timeWindow, $period);

        $row = $this->applyDimensionFilter(
            DB::table('stat_rollups')
                ->where('metric', $metric)
                ->where('period', $period)
                ->where('bucket', '>=', $since),
            $dimensionFilter,
        )
            ->selectRaw('COALESCE(SUM(count), 0) AS count, COALESCE(SUM(sum), 0) AS sum')
            ->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'sum' => (float) ($row->sum ?? 0),
        ];
    }

    /**
     * Group a metric by a single JSON dimension key, ordered by count
     * descending. Rows with a null dimension value are excluded. Reads
     * exactly one period — the same pick {@see series()} uses ('hour' for
     * windows ≤ 7d, 'day' otherwise) — so it neither double-counts the
     * parallel 'hour'/'day' rollups nor drops a sub-day window's hour buckets.
     *
     * @return list<array{key: string, count: int, sum: float}>
     */
    public function breakdown(string $metric, TimeWindow $timeWindow, string $dimensionKey): array
    {
        $period = $this->periodFor($timeWindow);
        $since = $this->windowStart($timeWindow, $period);

        return DB::table('stat_rollups')
            ->where('metric', $metric)
            ->where('period', $period)
            ->where('bucket', '>=', $since)
            ->whereRaw('dimensions->>? IS NOT NULL', [$dimensionKey])
            ->selectRaw('dimensions->>? AS key, SUM(count) AS count, COALESCE(SUM(sum), 0) AS sum', [$dimensionKey])
            ->groupByRaw('1')
            ->orderByDesc('count')
            ->get()
            ->map(fn (object $row): array => [
                'key' => (string) $row->key,
                'count' => (int) $row->count,
                'sum' => (float) $row->sum,
            ])
            ->all();
    }

    /**
     * Most-played titles in the window, computed live from
     * `emby_activities` (played actions only), grouped by the title we'd
     * display: the series title for episodes, else the media title.
     *
     * @return list<array{title: string, media_type: string, plays: int}>
     */
    public function topTitles(TimeWindow $timeWindow, int $limit = 10): array
    {
        $since = $timeWindow->cutoff() ?? CarbonImmutable::createFromTimestampUTC(0);

        return DB::table('emby_activities')
            ->where('action', 'played')
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(series_title, media_title) AS title, media_type, COUNT(*) AS plays')
            ->groupBy('title', 'media_type')
            ->orderByDesc('plays')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'title' => (string) $row->title,
                'media_type' => (string) $row->media_type,
                'plays' => (int) $row->plays,
            ])
            ->all();
    }

    /**
     * Per-user watch leaderboard: play counts and watched seconds pulled
     * from the `watch.user_plays` / `watch.seconds` rollups, keyed by
     * `emby_user_link_id`, then hydrated with a display name (linked user
     * name, falling back to the Emby username). Ordered by plays desc.
     *
     * @return list<array{user: string, plays: int, seconds: float}>
     */
    public function watchLeaderboard(TimeWindow $timeWindow): array
    {
        $plays = collect($this->breakdown('watch.user_plays', $timeWindow, 'emby_user_link_id'))
            ->keyBy('key');

        $seconds = collect($this->breakdown('watch.seconds', $timeWindow, 'emby_user_link_id'))
            ->keyBy('key');

        $linkIds = $plays->keys()->merge($seconds->keys())->unique();

        if ($linkIds->isEmpty()) {
            return [];
        }

        $links = EmbyUserLink::query()
            ->with('user')
            ->whereIn('id', $linkIds->map(fn (string $id): int => (int) $id)->all())
            ->get()
            ->keyBy(fn (EmbyUserLink $embyUserLink): string => (string) $embyUserLink->id);

        return $linkIds
            ->map(function (string $id) use ($plays, $seconds, $links): array {
                $link = $links->get($id);
                $displayName = $link?->user?->name ?: $link?->emby_username;

                return [
                    'user' => (string) ($displayName ?? $id),
                    'plays' => (int) ($plays->get($id)['count'] ?? 0),
                    'seconds' => (float) ($seconds->get($id)['sum'] ?? 0),
                ];
            })
            ->sortByDesc('plays')
            ->values()
            ->all();
    }

    /**
     * 7×24 matrix of play counts indexed by ISO weekday (1=Mon .. 7=Sun)
     * then hour-of-day (0..23), in the application timezone. Computed live
     * from `emby_activities` (played actions only); `created_at` is stored
     * as UTC and shifted into the app timezone before extraction so the
     * heatmap lines up with how the operator experiences the clock.
     *
     * @return array<int, array<int, int>>
     */
    public function watchHeatmap(TimeWindow $timeWindow): array
    {
        $since = $timeWindow->cutoff() ?? CarbonImmutable::createFromTimestampUTC(0);
        $appTimezone = config('app.timezone', 'UTC');

        $matrix = [];

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $matrix[$weekday] = array_fill(0, 24, 0);
        }

        $localTimestamp = "created_at AT TIME ZONE 'UTC' AT TIME ZONE ?";

        // Group by the SELECT ordinals so the timezone-shifted expression
        // is evaluated once per row rather than re-bound in GROUP BY (which
        // Postgres treats as a distinct parameter and rejects).
        $rows = DB::table('emby_activities')
            ->where('action', 'played')
            ->where('created_at', '>=', $since)
            ->selectRaw(
                sprintf('EXTRACT(isodow FROM %s)::int AS weekday, EXTRACT(hour FROM %s)::int AS hour, COUNT(*) AS plays', $localTimestamp, $localTimestamp),
                [$appTimezone, $appTimezone],
            )
            ->groupByRaw('1, 2')
            ->get();

        foreach ($rows as $row) {
            $matrix[(int) $row->weekday][(int) $row->hour] = (int) $row->plays;
        }

        return $matrix;
    }

    /**
     * 'hour' rows for windows whose cutoff falls within the last 7 days,
     * 'day' rows otherwise (including the unbounded `All` window).
     */
    private function periodFor(TimeWindow $timeWindow): string
    {
        $cutoff = $timeWindow->cutoff();

        if (! $cutoff instanceof CarbonImmutable) {
            return 'day';
        }

        // Minute buffer: cutoff() and this comparison read the clock at
        // slightly different instants, so an exact 7-day window (Last7d)
        // would otherwise flip between hour and day rows nondeterministically.
        return $cutoff >= CarbonImmutable::now()->subDays(7)->subMinute() ? 'hour' : 'day';
    }

    /**
     * Window start aligned to the resolved period's bucket boundary, in
     * UTC to match how rollup buckets are stored.
     */
    private function windowStart(TimeWindow $timeWindow, string $period): CarbonImmutable
    {
        $cutoff = ($timeWindow->cutoff() ?? CarbonImmutable::createFromTimestampUTC(0))->setTimezone('UTC');

        return $period === 'hour' ? $cutoff->startOfHour() : $cutoff->startOfDay();
    }

    /**
     * Apply an equality dimension filter using bound parameters for both
     * the JSON key and the value so nothing is interpolated into SQL.
     *
     * @param  array<string, scalar>  $dimensionFilter
     */
    private function applyDimensionFilter(QueryBuilder $queryBuilder, array $dimensionFilter): QueryBuilder
    {
        foreach ($dimensionFilter as $key => $value) {
            $queryBuilder->whereRaw('dimensions->>? = ?', [$key, (string) $value]);
        }

        return $queryBuilder;
    }
}
