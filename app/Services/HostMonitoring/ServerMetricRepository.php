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
 * is null, so a gap stays visible instead of the neighbours closing over it. Buckets are cut as SampleClock::bucket() says:
 * up to an hour they are instants, so the repeated hour of a DST fall-back night is two runs of points and a
 * spring-forward night has no phantom hour; longer ones follow the app timezone's wall clock (six-hour buckets start at
 * 00, 06, 12 and 18 local time all year).
 */
final readonly class ServerMetricRepository
{
    /** Whole buckets before the one holding now: 119 + 1 = the 120-point ceiling. */
    private const int EARLIER_BUCKETS = 119;

    /**
     * @return array{cpu: list<array{at: string, value: ?float}>, memory: list<array{at: string, value: ?float}>, filesystems: list<array{mount: string, points: list<array{at: string, value: ?float}>}>}
     */
    public function series(Server $server, ServerMetricRange $serverMetricRange): array
    {
        $slots = $this->slots($serverMetricRange);
        [$bucketSql, $bucketBindings] = SampleClock::bucket($serverMetricRange->bucketSeconds());

        $metricRows = $this->window(DB::table('server_metric_samples'), $server, $serverMetricRange, $slots[0])
            ->selectRaw($bucketSql.' AS bucket', $bucketBindings)
            ->selectRaw('avg(cpu_percent) AS cpu')
            ->selectRaw('avg(memory_used_bytes::float8 / NULLIF(memory_total_bytes, 0)) * 100 AS memory')
            ->groupBy('bucket')
            ->get();

        $filesystemRows = $this->window(DB::table('server_filesystem_samples'), $server, $serverMetricRange, $slots[0])
            ->select('mount')
            ->selectRaw($bucketSql.' AS bucket', $bucketBindings)
            ->selectRaw('avg(used_bytes::float8 / NULLIF(used_bytes + available_bytes, 0)) * 100 AS used')
            ->groupBy('mount', 'bucket')
            ->orderBy('mount')
            ->get();

        /** @var array<int, float> $cpu */
        $cpu = [];
        /** @var array<int, float> $memory */
        $memory = [];

        foreach ($metricRows as $metricRow) {
            $at = SampleClock::instant($metricRow->bucket);

            if ($metricRow->cpu !== null) {
                $cpu[$at] = (float) $metricRow->cpu;
            }

            if ($metricRow->memory !== null) {
                $memory[$at] = (float) $metricRow->memory;
            }
        }

        /** @var array<string, array<int, float>> $filesystems */
        $filesystems = [];

        foreach ($filesystemRows as $filesystemRow) {
            if ($filesystemRow->used !== null) {
                $filesystems[(string) $filesystemRow->mount][SampleClock::instant($filesystemRow->bucket)] = (float) $filesystemRow->used;
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
     * The window's bucket starts, oldest first, as Unix timestamps.
     *
     * @return non-empty-list<int>
     */
    private function slots(ServerMetricRange $serverMetricRange): array
    {
        return SampleClock::starts(now(), $serverMetricRange->bucketSeconds(), self::EARLIER_BUCKETS);
    }

    private function window(Builder $builder, Server $server, ServerMetricRange $serverMetricRange, int $windowStart): Builder
    {
        return $builder
            ->where('server_id', $server->id)
            ->where('resolution', $serverMetricRange->resolution())
            ->where('recorded_at', '>=', SampleClock::bind(CarbonImmutable::createFromTimestamp($windowStart)));
    }

    /**
     * Every slot of the window, or none when the series has no value at all (a new server keeps BarChart's empty line).
     *
     * @param  non-empty-list<int>  $slots
     * @param  array<int, float>  $values  keyed by bucket start
     * @return list<array{at: string, value: ?float}>
     */
    private function fill(array $slots, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return array_map(static fn (int $slot): array => [
            'at' => CarbonImmutable::createFromTimestamp($slot, (string) config('app.timezone'))->toIso8601String(),
            'value' => isset($values[$slot]) ? round($values[$slot], 1) : null,
        ], $slots);
    }
}
