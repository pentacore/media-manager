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
 */
final readonly class ServerMetricRepository
{
    /** Whole buckets before the one holding now: 119 + 1 = the 120-point ceiling. */
    private const int EARLIER_BUCKETS = 119;

    private const string BUCKET = "date_bin(make_interval(secs => CAST(? AS integer)), recorded_at, TIMESTAMP '2000-01-01')";

    private const string WINDOW_START = "date_bin(make_interval(secs => CAST(? AS integer)), CAST(? AS timestamp), TIMESTAMP '2000-01-01') - make_interval(secs => CAST(? AS integer))";

    /**
     * @return array{cpu: list<array{at: string, label: string, value: float}>, memory: list<array{at: string, label: string, value: float}>, filesystems: list<array{mount: string, points: list<array{at: string, label: string, value: float}>}>}
     */
    public function series(Server $server, ServerMetricRange $serverMetricRange): array
    {
        $metricRows = $this->window(DB::table('server_metric_samples'), $server, $serverMetricRange)
            ->selectRaw(sprintf('%s AS bucket', self::BUCKET), [$serverMetricRange->bucketSeconds()])
            ->selectRaw('avg(cpu_percent) AS cpu')
            ->selectRaw('avg(memory_used_bytes::float8 / NULLIF(memory_total_bytes, 0)) * 100 AS memory')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $filesystemRows = $this->window(DB::table('server_filesystem_samples'), $server, $serverMetricRange)
            ->select('mount')
            ->selectRaw(sprintf('%s AS bucket', self::BUCKET), [$serverMetricRange->bucketSeconds()])
            ->selectRaw('avg(used_bytes::float8 / NULLIF(used_bytes + available_bytes, 0)) * 100 AS used')
            ->groupBy('mount', 'bucket')
            ->orderBy('mount')
            ->orderBy('bucket')
            ->get();

        $cpu = [];
        $memory = [];

        foreach ($metricRows as $metricRow) {
            if ($metricRow->cpu !== null) {
                $cpu[] = $this->point((string) $metricRow->bucket, (float) $metricRow->cpu, $serverMetricRange);
            }

            if ($metricRow->memory !== null) {
                $memory[] = $this->point((string) $metricRow->bucket, (float) $metricRow->memory, $serverMetricRange);
            }
        }

        /** @var array<string, list<array{at: string, label: string, value: float}>> $filesystems */
        $filesystems = [];

        foreach ($filesystemRows as $filesystemRow) {
            if ($filesystemRow->used === null) {
                continue;
            }

            $filesystems[(string) $filesystemRow->mount][] = $this->point((string) $filesystemRow->bucket, (float) $filesystemRow->used, $serverMetricRange);
        }

        return [
            'cpu' => $cpu,
            'memory' => $memory,
            'filesystems' => array_map(
                static fn (string $mount, array $points): array => ['mount' => $mount, 'points' => $points],
                array_map(strval(...), array_keys($filesystems)),
                array_values($filesystems),
            ),
        ];
    }

    private function window(Builder $builder, Server $server, ServerMetricRange $serverMetricRange): Builder
    {
        $bucketSeconds = $serverMetricRange->bucketSeconds();

        return $builder
            ->where('server_id', $server->id)
            ->where('resolution', $serverMetricRange->resolution())
            ->whereRaw(sprintf('recorded_at >= %s', self::WINDOW_START), [$bucketSeconds, now()->toDateTimeString(), $bucketSeconds * self::EARLIER_BUCKETS]);
    }

    /**
     * Bucket times are stored in the app timezone, so the label reads them as such.
     *
     * @return array{at: string, label: string, value: float}
     */
    private function point(string $bucket, float $value, ServerMetricRange $serverMetricRange): array
    {
        $at = CarbonImmutable::parse($bucket, config('app.timezone'));

        return [
            'at' => $at->toIso8601String(),
            'label' => $at->format(match ($serverMetricRange) {
                ServerMetricRange::SixHours, ServerMetricRange::TwoDays => 'H:i',
                ServerMetricRange::ThirtyDays, ServerMetricRange::OneYear => 'M j',
            }),
            'value' => round($value, 1),
        ];
    }
}
