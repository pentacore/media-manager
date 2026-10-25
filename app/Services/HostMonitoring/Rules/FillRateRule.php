<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Models\ServerFilesystemSample;
use App\Models\ServerMetricSample;
use App\Services\HostMonitoring\AgentReport;
use App\Services\HostMonitoring\IgnoredMounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/**
 * Predicts when each mount fills up from its recent 60 s samples of free space, and raises `fill:{mount}` when that is
 * under the configured hours. The window's samples are split in two halves at the time midpoint between the first and
 * the last sample, each half needing at least half of `min_samples` (rounded down, so 15 contiguous minutes are judged),
 * and each gets a least-squares line. Only a decline in both halves counts, and the time to full comes from the slower
 * of the two: a single large write, routine on a media server, steepens one half only and cannot raise an alarm on its
 * own, at the cost of reporting an accelerating fill later. A mount that does not answer, has too little history or no
 * free space left (DiskUsageRule covers that) gets no verdict; an empty filesystem list (an agent fault) is skipped so
 * it cannot sweep every fill incident away. Ignored mounts (IgnoredMounts) are left out, so the sweep resolves theirs.
 */
final readonly class FillRateRule implements ReportRule
{
    public function ownsKey(string $key): bool
    {
        return str_starts_with($key, 'fill:');
    }

    /**
     * @return list<IncidentObservation>|null
     */
    public function evaluate(Server $server, AgentReport $agentReport): ?array
    {
        if ($agentReport->filesystems === []) {
            return null;
        }

        $filesystems = IgnoredMounts::reject($agentReport->filesystems);
        $samplesByMount = $this->samplesByMount($server, $filesystems, $agentReport->receivedAt);
        $observations = [];

        foreach ($filesystems as $filesystem) {
            $key = sprintf('fill:%s', $filesystem['mount']);

            $observations[$key] = $filesystem['unresponsive']
                ? IncidentObservation::hold($key)
                : $this->observe($samplesByMount[$filesystem['mount']] ?? [], $key, $filesystem['mount'], (int) $filesystem['available_bytes']);
        }

        return array_values($observations);
    }

    /**
     * The window's 60 s samples of every responsive mount given, oldest first, in one query.
     *
     * @param  list<array{mount: string, device: ?string, fstype: ?string, unresponsive: bool, total_bytes: ?int, used_bytes: ?int, available_bytes: ?int}>  $filesystems
     * @return array<string, list<array{recorded_at: CarbonImmutable, available_bytes: int}>>
     */
    private function samplesByMount(Server $server, array $filesystems, CarbonImmutable $receivedAt): array
    {
        $mounts = array_column(array_filter($filesystems, static fn (array $filesystem): bool => ! $filesystem['unresponsive']), 'mount');

        if ($mounts === []) {
            return [];
        }

        $samples = ServerFilesystemSample::query()
            ->where('server_id', $server->id)
            ->where('resolution', ServerMetricSample::MINUTE)
            ->whereIn('mount', $mounts)
            ->where('recorded_at', '>=', $receivedAt->subMinutes((int) config('mediamanager.monitoring.fill_rate.window_minutes')))
            ->oldest('recorded_at')
            ->get(['mount', 'recorded_at', 'available_bytes']);
        $samplesByMount = [];

        foreach ($samples as $sample) {
            $samplesByMount[$sample->mount][] = ['recorded_at' => $sample->recorded_at, 'available_bytes' => $sample->available_bytes];
        }

        return $samplesByMount;
    }

    /**
     * @param  list<array{recorded_at: CarbonImmutable, available_bytes: int}>  $samples
     */
    private function observe(array $samples, string $key, string $mount, int $availableNow): IncidentObservation
    {
        $minSamples = (int) config('mediamanager.monitoring.fill_rate.min_samples');

        if ($availableNow <= 0 || $samples === [] || count($samples) < $minSamples) {
            return IncidentObservation::hold($key);
        }

        [$earlierHalf, $recentHalf] = $this->halves($samples);
        $minHalfSamples = intdiv($minSamples, 2);

        if (count($earlierHalf) < $minHalfSamples || count($recentHalf) < $minHalfSamples) {
            return IncidentObservation::hold($key);
        }

        $earlierSlope = $this->slopePerHour($earlierHalf);
        $recentSlope = $this->slopePerHour($recentHalf);

        if ($earlierSlope === null || $recentSlope === null) {
            return IncidentObservation::hold($key);
        }

        if ($earlierSlope >= 0 || $recentSlope >= 0) {
            return IncidentObservation::clear($key);
        }

        $slope = max($earlierSlope, $recentSlope);

        $hoursToFull = $availableNow / -$slope;
        $severity = match (true) {
            $hoursToFull < (int) config('mediamanager.monitoring.fill_rate.critical_hours') => IncidentSeverity::Critical,
            $hoursToFull < (int) config('mediamanager.monitoring.fill_rate.warning_hours') => IncidentSeverity::Warning,
            default => null,
        };

        if ($severity === null) {
            return IncidentObservation::clear($key);
        }

        $eta = $this->eta($hoursToFull);

        return IncidentObservation::raise(
            $key,
            $severity,
            sprintf('Disk %s fills in %s', $mount, $eta),
            sprintf('~%s until full (losing %s/h).', $eta, Number::fileSize((int) round(-$slope), maxPrecision: 1)),
            [
                'mount' => $mount,
                'slope_bytes_per_hour' => (int) round($slope),
                'earlier_slope_bytes_per_hour' => (int) round($earlierSlope),
                'recent_slope_bytes_per_hour' => (int) round($recentSlope),
                'hours_to_full' => round($hoursToFull, 2),
                'samples' => count($samples),
            ],
        );
    }

    /**
     * The samples before and from the midpoint between the first and the last sample's time.
     *
     * @param  non-empty-list<array{recorded_at: CarbonImmutable, available_bytes: int}>  $samples
     * @return array{0: list<array{recorded_at: CarbonImmutable, available_bytes: int}>, 1: list<array{recorded_at: CarbonImmutable, available_bytes: int}>}
     */
    private function halves(array $samples): array
    {
        $first = $samples[0]['recorded_at'];
        $midpoint = $first->addSeconds((int) floor($first->diffInSeconds(array_last($samples)['recorded_at']) / 2));
        $earlier = [];
        $recent = [];

        foreach ($samples as $sample) {
            if ($sample['recorded_at']->lessThan($midpoint)) {
                $earlier[] = $sample;
            } else {
                $recent[] = $sample;
            }
        }

        return [$earlier, $recent];
    }

    /**
     * Least-squares slope of free bytes over hours since the first sample; null without samples or when they share one time.
     *
     * @param  list<array{recorded_at: CarbonImmutable, available_bytes: int}>  $samples
     */
    private function slopePerHour(array $samples): ?float
    {
        if ($samples === []) {
            return null;
        }

        $first = $samples[0]['recorded_at'];
        $points = array_map(static fn (array $sample): array => [
            $first->diffInSeconds($sample['recorded_at']) / 3600,
            (float) $sample['available_bytes'],
        ], $samples);

        $meanX = array_sum(array_column($points, 0)) / count($points);
        $meanY = array_sum(array_column($points, 1)) / count($points);
        $covariance = 0.0;
        $varianceX = 0.0;

        foreach ($points as [$x, $y]) {
            $covariance += ($x - $meanX) * ($y - $meanY);
            $varianceX += ($x - $meanX) ** 2;
        }

        return $varianceX > 0.0 ? $covariance / $varianceX : null;
    }

    private function eta(float $hours): string
    {
        return match (true) {
            $hours < 1 => sprintf('%d min', min(59, max(1, (int) round($hours * 60)))),
            $hours < 48 => sprintf('%d h', (int) round($hours)),
            default => sprintf('%d d', (int) round($hours / 24)),
        };
    }
}
