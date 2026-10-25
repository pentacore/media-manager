<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Services\HostMonitoring\AgentReport;
use App\Services\HostMonitoring\IgnoredMounts;
use Illuminate\Support\Number;

/**
 * Raises `disk:{mount}` at the configured usage thresholds, and as critical while a mount does not answer at all.
 * The same key carries both, so a mount that answers again is judged on its usage from the next report on. A mount
 * clears only once its usage is `clear_margin_percent` points under the warning threshold; in between it is held, so
 * a disk wobbling around the threshold keeps one incident. An empty filesystem list (an agent fault) is skipped, so it
 * cannot sweep every disk incident away; ignored mounts (IgnoredMounts) are left out, so the sweep resolves theirs.
 */
final readonly class DiskUsageRule implements ReportRule
{
    public function ownsKey(string $key): bool
    {
        return str_starts_with($key, 'disk:');
    }

    /**
     * @return list<IncidentObservation>|null
     */
    public function evaluate(Server $server, AgentReport $agentReport): ?array
    {
        if ($agentReport->filesystems === []) {
            return null;
        }

        $warningPercent = (int) config('mediamanager.monitoring.disk.warning_percent');
        $criticalPercent = (int) config('mediamanager.monitoring.disk.critical_percent');
        $clearBelowPercent = $warningPercent - (int) config('mediamanager.monitoring.disk.clear_margin_percent');
        $observations = [];

        foreach (IgnoredMounts::reject($agentReport->filesystems) as $filesystem) {
            $mount = $filesystem['mount'];
            $key = sprintf('disk:%s', $mount);

            if ($filesystem['unresponsive']) {
                $observations[$key] = IncidentObservation::raise(
                    $key,
                    IncidentSeverity::Critical,
                    sprintf('Disk %s is not responding', $mount),
                    'The mount did not answer in time (a hung network mount?).',
                    ['mount' => $mount, 'fstype' => $filesystem['fstype'], 'unresponsive' => true],
                );

                continue;
            }

            $used = (int) $filesystem['used_bytes'];
            $available = (int) $filesystem['available_bytes'];
            $size = $used + $available;

            if ($size <= 0) {
                $observations[$key] = IncidentObservation::hold($key);

                continue;
            }

            $severity = match (true) {
                $used * 100 >= $criticalPercent * $size => IncidentSeverity::Critical,
                $used * 100 >= $warningPercent * $size => IncidentSeverity::Warning,
                default => null,
            };

            if ($severity === null) {
                $observations[$key] = $used * 100 > $clearBelowPercent * $size
                    ? IncidentObservation::hold($key)
                    : IncidentObservation::clear($key);

                continue;
            }

            $observations[$key] = IncidentObservation::raise(
                $key,
                $severity,
                sprintf('Disk %s is %d%% full', $mount, intdiv($used * 100, $size)),
                sprintf(
                    '%s used of %s, %s free.',
                    Number::fileSize($used, maxPrecision: 1),
                    Number::fileSize($size, maxPrecision: 1),
                    Number::fileSize($available, maxPrecision: 1),
                ),
                ['mount' => $mount, 'used_bytes' => $used, 'available_bytes' => $available, 'percent' => round($used / $size * 100, 1)],
            );
        }

        return array_values($observations);
    }
}
