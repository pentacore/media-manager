<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for stat_rollups. Additive writers (increment/sample)
 * land in both hour and day buckets; put() overwrites one row and is the
 * aggregator's idempotent-recompute primitive. Stateless — Octane-safe.
 */
class StatsRecorder
{
    private const string ADDITIVE_SQL = <<<'SQL'
        INSERT INTO stat_rollups (metric, period, bucket, dimensions, count, sum, min, max, created_at, updated_at)
        VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, now(), now())
        ON CONFLICT (metric, period, bucket, dimensions) DO UPDATE SET
            count = stat_rollups.count + excluded.count,
            sum = CASE WHEN excluded.sum IS NULL THEN stat_rollups.sum
                       ELSE COALESCE(stat_rollups.sum, 0) + excluded.sum END,
            min = LEAST(COALESCE(stat_rollups.min, excluded.min), excluded.min),
            max = GREATEST(COALESCE(stat_rollups.max, excluded.max), excluded.max),
            updated_at = now()
        SQL;

    private const string OVERWRITE_SQL = <<<'SQL'
        INSERT INTO stat_rollups (metric, period, bucket, dimensions, count, sum, min, max, created_at, updated_at)
        VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, now(), now())
        ON CONFLICT (metric, period, bucket, dimensions) DO UPDATE SET
            count = excluded.count,
            sum = excluded.sum,
            min = excluded.min,
            max = excluded.max,
            updated_at = now()
        SQL;

    /**
     * @param  array<string, string|int>  $dimensions
     */
    public function increment(string $metric, array $dimensions, CarbonImmutable $at, int|float $count = 1, int|float|null $sum = null): void
    {
        foreach (['hour', 'day'] as $period) {
            $this->write(self::ADDITIVE_SQL, $metric, $period, $this->bucketFor($at, $period), $dimensions, $count, $sum, null, null);
        }
    }

    /**
     * @param  array<string, string|int>  $dimensions
     */
    public function sample(string $metric, array $dimensions, CarbonImmutable $at, float $value): void
    {
        foreach (['hour', 'day'] as $period) {
            $this->write(self::ADDITIVE_SQL, $metric, $period, $this->bucketFor($at, $period), $dimensions, 1, $value, $value, $value);
        }
    }

    /**
     * @param  array<string, string|int>  $dimensions
     */
    public function put(string $metric, string $period, CarbonImmutable $bucket, array $dimensions, int|float $count, int|float|null $sum = null, ?float $min = null, ?float $max = null): void
    {
        $this->write(self::OVERWRITE_SQL, $metric, $period, $bucket, $dimensions, $count, $sum, $min, $max);
    }

    /**
     * @param  array<string, string|int>  $dimensions
     */
    private function write(string $sql, string $metric, string $period, CarbonImmutable $bucket, array $dimensions, int|float $count, int|float|null $sum, ?float $min, ?float $max): void
    {
        ksort($dimensions);

        DB::statement($sql, [
            $metric,
            $period,
            $bucket->utc()->toDateTimeString(),
            json_encode($dimensions, JSON_THROW_ON_ERROR),
            $count,
            $sum,
            $min,
            $max,
        ]);
    }

    private function bucketFor(CarbonImmutable $at, string $period): CarbonImmutable
    {
        return $period === 'hour' ? $at->utc()->startOfHour() : $at->utc()->startOfDay();
    }
}
