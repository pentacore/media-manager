<?php

declare(strict_types=1);

namespace App\Services\ServiceMetrics;

use App\Enums\HealthStatus;
use App\Models\ServiceMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceMetricsRepository
{
    /**
     * Returns 60 minute buckets for one connection. Each bucket is the
     * worst observed status (down > degraded > unknown > healthy) within
     * its minute, plus the average latency. Empty minutes return a 'gap'
     * bucket so the strip renders them visually.
     *
     * @return array<int, array{minute: int, status: string, latency_ms: int|null}>
     */
    public function last60Minutes(int $serviceConnectionId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subMinutes(60)->startOfMinute();

        $rows = ServiceMetric::query()
            ->where('service_connection_id', $serviceConnectionId)
            ->where('recorded_at', '>=', $cutoff)
            ->oldest('recorded_at')
            ->get(['status', 'latency_ms', 'recorded_at']);

        $buckets = [];

        for ($i = 0; $i < 60; $i++) {
            $buckets[$i] = [
                'minute' => $i,
                'status' => 'gap',
                'latency_ms' => null,
            ];
        }

        $latencyAccum = array_fill(0, 60, []);

        foreach ($rows as $row) {
            $minutesAgo = (int) $cutoff->diffInMinutes($row->recorded_at);
            if ($minutesAgo < 0) {
                continue;
            }
            if ($minutesAgo > 59) {
                continue;
            }

            $idx = $minutesAgo;
            $current = $buckets[$idx]['status'];
            $incoming = $row->status->value;
            $buckets[$idx]['status'] = $this->worse($current, $incoming);

            if ($row->latency_ms !== null) {
                $latencyAccum[$idx][] = $row->latency_ms;
            }
        }

        foreach ($latencyAccum as $idx => $samples) {
            if ($samples === []) {
                continue;
            }

            $buckets[$idx]['latency_ms'] = (int) round(array_sum($samples) / count($samples));
        }

        return $buckets;
    }

    /**
     * Returns the latest N latency samples for a connection, oldest first.
     * Used to draw per-card sparklines on the dashboard.
     *
     * @return array<int, int>
     */
    public function recentLatencySamples(int $serviceConnectionId, int $limit = 18): array
    {
        return ServiceMetric::query()
            ->where('service_connection_id', $serviceConnectionId)
            ->whereNotNull('latency_ms')
            ->latest('recorded_at')
            ->limit($limit)
            ->pluck('latency_ms')
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * Returns the average healthy-fraction for the connection over the
     * given window, expressed as a 0-100 percentage. Returns null when
     * there are no samples (so the UI can show "—" instead of 100%).
     */
    public function uptimePercent(int $serviceConnectionId, ?CarbonImmutable $since = null): ?float
    {
        $since ??= CarbonImmutable::now()->subDays(30);

        $row = DB::table('service_metrics')
            ->selectRaw('
                COUNT(*) AS total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS healthy
            ', [HealthStatus::Healthy->value])
            ->where('service_connection_id', $serviceConnectionId)
            ->where('recorded_at', '>=', $since)
            ->first();

        if ($row === null || (int) $row->total === 0) {
            return null;
        }

        return round((float) $row->healthy / (float) $row->total * 100, 2);
    }

    /**
     * Returns the average latency over the recent window for a connection.
     * Used by the dashboard service-health mini-list.
     */
    public function averageLatencyMs(int $serviceConnectionId, ?CarbonImmutable $since = null): ?int
    {
        $since ??= CarbonImmutable::now()->subHour();

        $avg = ServiceMetric::query()
            ->where('service_connection_id', $serviceConnectionId)
            ->whereNotNull('latency_ms')
            ->where('recorded_at', '>=', $since)
            ->avg('latency_ms');

        return $avg === null ? null : (int) round((float) $avg);
    }

    /**
     * Pre-fetch the last 60 minutes for many connections in one query and
     * group the result client-side, so the service-health page doesn't
     * fire one query per connection.
     *
     * @param  array<int, int>  $serviceConnectionIds
     * @return array<int, array<int, array{minute: int, status: string, latency_ms: int|null}>>
     */
    public function last60MinutesFor(array $serviceConnectionIds, ?CarbonImmutable $now = null): array
    {
        if ($serviceConnectionIds === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();
        $cutoff = $now->subMinutes(60)->startOfMinute();

        $rows = ServiceMetric::query()
            ->whereIn('service_connection_id', $serviceConnectionIds)
            ->where('recorded_at', '>=', $cutoff)
            ->oldest('recorded_at')
            ->get(['service_connection_id', 'status', 'latency_ms', 'recorded_at'])
            ->groupBy('service_connection_id');

        $out = [];

        foreach ($serviceConnectionIds as $serviceConnectionId) {
            $out[$serviceConnectionId] = $this->bucketize($rows->get($serviceConnectionId) ?? collect(), $cutoff);
        }

        return $out;
    }

    /**
     * Bucketize a pre-fetched collection of metrics for a single
     * connection. Same shape as last60Minutes() but operates on the
     * already-loaded rows.
     *
     * @param  Collection<int, ServiceMetric>  $rows
     * @return array<int, array{minute: int, status: string, latency_ms: int|null}>
     */
    private function bucketize(Collection $rows, CarbonImmutable $cutoff): array
    {
        $buckets = [];

        for ($i = 0; $i < 60; $i++) {
            $buckets[$i] = [
                'minute' => $i,
                'status' => 'gap',
                'latency_ms' => null,
            ];
        }

        $latencyAccum = array_fill(0, 60, []);

        foreach ($rows as $row) {
            $minutesAgo = (int) $cutoff->diffInMinutes($row->recorded_at);
            if ($minutesAgo < 0) {
                continue;
            }
            if ($minutesAgo > 59) {
                continue;
            }

            $idx = $minutesAgo;
            $buckets[$idx]['status'] = $this->worse($buckets[$idx]['status'], $row->status->value);

            if ($row->latency_ms !== null) {
                $latencyAccum[$idx][] = $row->latency_ms;
            }
        }

        foreach ($latencyAccum as $idx => $samples) {
            if ($samples === []) {
                continue;
            }

            $buckets[$idx]['latency_ms'] = (int) round(array_sum($samples) / count($samples));
        }

        return $buckets;
    }

    /**
     * Pick the worse of two status strings using a fixed severity rank.
     */
    private function worse(string $current, string $incoming): string
    {
        $rank = [
            'gap' => 0,
            'healthy' => 1,
            'unknown' => 2,
            'degraded' => 3,
            'unhealthy' => 4,
        ];

        $currentRank = $rank[$current] ?? 0;
        $incomingRank = $rank[$incoming] ?? 0;

        return $incomingRank > $currentRank ? $incoming : $current;
    }
}
