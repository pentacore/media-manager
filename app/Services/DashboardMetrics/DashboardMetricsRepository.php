<?php

declare(strict_types=1);

namespace App\Services\DashboardMetrics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class DashboardMetricsRepository
{
    /**
     * Hour-bucketed count of webhook deliveries in the last `hours`
     * window. Returns an array of `hours` integers, oldest first.
     *
     * @return array<int, int>
     */
    public function webhookSparkline(int $hours = 24, ?CarbonImmutable $now = null): array
    {
        return $this->hourlyCounts(
            DB::table('webhook_events'),
            'created_at',
            $hours,
            $now,
        );
    }

    /**
     * Hour-bucketed count of action requests in the last `hours` window.
     *
     * @return array<int, int>
     */
    public function actionSparkline(int $hours = 24, ?CarbonImmutable $now = null): array
    {
        return $this->hourlyCounts(
            DB::table('action_requests'),
            'created_at',
            $hours,
            $now,
        );
    }

    /**
     * Hour-bucketed count of failed action requests in the last `hours`
     * window. Used to overlay a warning bar on the Actions card.
     *
     * @return array<int, int>
     */
    public function failedActionSparkline(int $hours = 24, ?CarbonImmutable $now = null): array
    {
        return $this->hourlyCounts(
            DB::table('action_requests')->where('status', 'failed'),
            'created_at',
            $hours,
            $now,
        );
    }

    /**
     * Hour-bucketed count of Emby playback events. Useful as a coarse
     * "concurrent streams" proxy on the dashboard's Streams card.
     *
     * @return array<int, int>
     */
    public function streamSparkline(int $hours = 24, ?CarbonImmutable $now = null): array
    {
        return $this->hourlyCounts(
            DB::table('emby_activities')->where('action', 'played'),
            'created_at',
            $hours,
            $now,
        );
    }

    /**
     * Group rows on the given builder by hour-of-day for the last
     * `hours` window and return a fixed-length array (oldest first).
     * Empty hours come back as 0 so the sparkline path stays continuous.
     *
     * @return array<int, int>
     */
    private function hourlyCounts(
        QueryBuilder $queryBuilder,
        string $timestampColumn,
        int $hours,
        ?CarbonImmutable $now,
    ): array {
        $now ??= CarbonImmutable::now();
        $startOfBucket = $now->subHours($hours - 1)->startOfHour();

        $rows = (clone $queryBuilder)
            ->where($timestampColumn, '>=', $startOfBucket)
            ->selectRaw(sprintf("date_trunc('hour', %s) AS hour, COUNT(*) AS count", $timestampColumn))
            ->groupBy('hour')
            ->get()
            ->keyBy(fn (object $row): string => CarbonImmutable::parse((string) $row->hour, 'UTC')->format('Y-m-d H:00'));

        $buckets = [];

        for ($i = 0; $i < $hours; $i++) {
            $key = $startOfBucket->addHours($i)->format('Y-m-d H:00');
            $buckets[] = (int) ($rows->get($key)->count ?? 0);
        }

        return $buckets;
    }
}
