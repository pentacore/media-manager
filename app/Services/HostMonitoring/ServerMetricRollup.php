<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Models\ServerMetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rolls closed buckets of finer samples up into the next resolution: 60 s into 300 s, 300 s into 3600 s.
 * Load-like values (cpu, load, memory and swap used, filesystem used and available) are averaged, capacities
 * (memory, swap and filesystem totals) take the maximum. Each run re-reads a lookback window and upserts, so it
 * is idempotent and picks up late samples. A bucket averages whichever source rows it has: a mount with no
 * 60 s rows (unresponsive) simply contributes none.
 */
final readonly class ServerMetricRollup
{
    /**
     * @var array<int, array{source: int, lookbackHours: int}>
     */
    private const array TIERS = [
        ServerMetricSample::FIVE_MINUTES => ['source' => ServerMetricSample::MINUTE, 'lookbackHours' => 2],
        ServerMetricSample::HOUR => ['source' => ServerMetricSample::FIVE_MINUTES, 'lookbackHours' => 6],
    ];

    /**
     * @return int rows upserted across both tables
     *
     * @throws InvalidArgumentException when the resolution is not a rollup target
     */
    public function rollup(int $targetResolution): int
    {
        $tier = self::TIERS[$targetResolution] ?? throw new InvalidArgumentException(
            sprintf('Cannot roll up to a %d s resolution; expected 300 or 3600.', $targetResolution),
        );

        $to = $this->floorToBucket(now()->toImmutable(), $targetResolution);
        $from = $to->subHours($tier['lookbackHours']);

        return DB::transaction(fn (): int => $this->rollupMetrics($targetResolution, $tier['source'], $from, $to)
            + $this->rollupFilesystems($targetResolution, $tier['source'], $from, $to));
    }

    private function floorToBucket(CarbonImmutable $moment, int $seconds): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($moment->getTimestamp() - ($moment->getTimestamp() % $seconds));
    }

    private function rollupMetrics(int $target, int $source, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::affectingStatement(
            <<<'SQL'
                INSERT INTO server_metric_samples (server_id, resolution, recorded_at, cpu_percent, load1, memory_used_bytes, memory_total_bytes, swap_used_bytes, swap_total_bytes)
                SELECT server_id, CAST(? AS smallint), date_bin(make_interval(secs => CAST(? AS integer)), recorded_at, TIMESTAMP '2000-01-01') AS bucket,
                       avg(cpu_percent), avg(load1), avg(memory_used_bytes)::bigint, max(memory_total_bytes),
                       avg(swap_used_bytes)::bigint, max(swap_total_bytes)
                FROM server_metric_samples
                WHERE resolution = ? AND recorded_at >= ? AND recorded_at < ?
                GROUP BY server_id, bucket
                ON CONFLICT (server_id, resolution, recorded_at) DO UPDATE SET
                    cpu_percent = EXCLUDED.cpu_percent,
                    load1 = EXCLUDED.load1,
                    memory_used_bytes = EXCLUDED.memory_used_bytes,
                    memory_total_bytes = EXCLUDED.memory_total_bytes,
                    swap_used_bytes = EXCLUDED.swap_used_bytes,
                    swap_total_bytes = EXCLUDED.swap_total_bytes
                SQL,
            [$target, $target, $source, $from->toDateTimeString(), $to->toDateTimeString()],
        );
    }

    private function rollupFilesystems(int $target, int $source, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::affectingStatement(
            <<<'SQL'
                INSERT INTO server_filesystem_samples (server_id, resolution, recorded_at, mount, fstype, total_bytes, used_bytes, available_bytes)
                SELECT server_id, CAST(? AS smallint), date_bin(make_interval(secs => CAST(? AS integer)), recorded_at, TIMESTAMP '2000-01-01') AS bucket,
                       mount, max(fstype), max(total_bytes), avg(used_bytes)::bigint, avg(available_bytes)::bigint
                FROM server_filesystem_samples
                WHERE resolution = ? AND recorded_at >= ? AND recorded_at < ?
                GROUP BY server_id, mount, bucket
                ON CONFLICT (server_id, resolution, mount, recorded_at) DO UPDATE SET
                    fstype = EXCLUDED.fstype,
                    total_bytes = EXCLUDED.total_bytes,
                    used_bytes = EXCLUDED.used_bytes,
                    available_bytes = EXCLUDED.available_bytes
                SQL,
            [$target, $target, $source, $from->toDateTimeString(), $to->toDateTimeString()],
        );
    }
}
