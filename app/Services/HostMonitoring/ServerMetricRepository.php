<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Enums\ServerMetricRange;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads a server's chart series from the sample tier its range uses, averaged into at most 120 buckets.
 * The window starts on a bucket boundary 119 buckets before the one holding now, so it never spans a 121st bucket.
 * A series with any data has every bucket of the window; a bucket without samples (an outage, an unresponsive mount)
 * is null, so a gap stays visible instead of the neighbours closing over it.
 */
final readonly class ServerMetricRepository
{
    /** Whole buckets before the one holding now: 119 + 1 = the 120-point ceiling. */
    private const int EARLIER_BUCKETS = 119;

    /** The `date_bin` origin; stored times are app-timezone wall clock without a zone. */
    private const string ORIGIN = '2000-01-01 00:00:00';

    private const string BUCKET = "date_bin(make_interval(secs => CAST(? AS integer)), recorded_at, TIMESTAMP '2000-01-01')";

    /**
     * @return array{cpu: list<array{at: string, value: ?float}>, memory: list<array{at: string, value: ?float}>, filesystems: list<array{mount: string, points: list<array{at: string, value: ?float}>}>}
     */
    public function series(Server $server, ServerMetricRange $serverMetricRange): array
    {
        $slots = $this->slots($serverMetricRange);

        $metricRows = $this->window(DB::table('server_metric_samples'), $server, $serverMetricRange, $slots[0])
            ->selectRaw(sprintf('%s AS bucket', self::BUCKET), [$serverMetricRange->bucketSeconds()])
            ->selectRaw('avg(cpu_percent) AS cpu')
            ->selectRaw('avg(memory_used_bytes::float8 / NULLIF(memory_total_bytes, 0)) * 100 AS memory')
            ->groupBy('bucket')
            ->get();

        $filesystemRows = $this->window(DB::table('server_filesystem_samples'), $server, $serverMetricRange, $slots[0])
            ->select('mount')
            ->selectRaw(sprintf('%s AS bucket', self::BUCKET), [$serverMetricRange->bucketSeconds()])
            ->selectRaw('avg(used_bytes::float8 / NULLIF(used_bytes + available_bytes, 0)) * 100 AS used')
            ->groupBy('mount', 'bucket')
            ->orderBy('mount')
            ->get();

        /** @var array<string, float> $cpu */
        $cpu = [];
        /** @var array<string, float> $memory */
        $memory = [];

        foreach ($metricRows as $metricRow) {
            if ($metricRow->cpu !== null) {
                $cpu[(string) $metricRow->bucket] = (float) $metricRow->cpu;
            }

            if ($metricRow->memory !== null) {
                $memory[(string) $metricRow->bucket] = (float) $metricRow->memory;
            }
        }

        /** @var array<string, array<string, float>> $filesystems */
        $filesystems = [];

        foreach ($filesystemRows as $filesystemRow) {
            if ($filesystemRow->used !== null) {
                $filesystems[(string) $filesystemRow->mount][(string) $filesystemRow->bucket] = (float) $filesystemRow->used;
            }
        }

        return [
            'cpu' => $this->fill($slots, $cpu),
            'memory' => $this->fill($slots, $memory),
            'filesystems' => array_map(
                fn (string $mount, array $values): array => ['mount' => $mount, 'points' => $this->fill($slots, $values)],
                array_map(strval(...), array_keys($filesystems)),
                array_values($filesystems),
            ),
        ];
    }

    /**
     * The window's bucket starts, oldest first, as stored wall-clock strings (`Y-m-d H:i:s`).
     *
     * @return non-empty-list<string>
     */
    private function slots(ServerMetricRange $serverMetricRange): array
    {
        $bucketSeconds = $serverMetricRange->bucketSeconds();
        // Read the app-timezone wall clock as if it were UTC, as Postgres reads a timestamp without a zone.
        $wallClock = CarbonImmutable::parse(now()->toDateTimeString(), 'UTC');
        $sinceOrigin = $wallClock->getTimestamp() - CarbonImmutable::parse(self::ORIGIN, 'UTC')->getTimestamp();
        $current = $wallClock->subSeconds($sinceOrigin % $bucketSeconds);

        return array_map(
            static fn (int $earlier): string => $current->subSeconds($earlier * $bucketSeconds)->toDateTimeString(),
            range(self::EARLIER_BUCKETS, 0),
        );
    }

    private function window(Builder $builder, Server $server, ServerMetricRange $serverMetricRange, string $windowStart): Builder
    {
        return $builder
            ->where('server_id', $server->id)
            ->where('resolution', $serverMetricRange->resolution())
            ->where('recorded_at', '>=', $windowStart);
    }

    /**
     * Every slot of the window, or none when the series has no value at all (a new server keeps BarChart's empty line).
     *
     * @param  non-empty-list<string>  $slots
     * @param  array<string, float>  $values  keyed by bucket start
     * @return list<array{at: string, value: ?float}>
     */
    private function fill(array $slots, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return array_map(static fn (string $slot): array => [
            'at' => CarbonImmutable::parse($slot, config('app.timezone'))->toIso8601String(),
            'value' => isset($values[$slot]) ? round($values[$slot], 1) : null,
        ], $slots);
    }
}
