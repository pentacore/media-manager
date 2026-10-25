<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Services\HostMonitoring\AgentReport;
use App\Services\HostMonitoring\IgnoredMounts;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Number;

/**
 * Raises `disk:{mount}` at the configured usage thresholds, and as critical while a mount does not answer at all.
 * The same key carries both, so a mount that answers again is judged on its usage from the next report on. A usage
 * incident clears only once its usage is `clear_margin_percent` points under the warning threshold; in the band
 * between, a warning is held (a disk wobbling around the threshold keeps one incident) and a critical steps down to a
 * warning with a fresh title. A not-responding incident is not a usage problem, so it clears as soon as the mount
 * answers under the warning threshold. An empty filesystem list (an agent fault) is skipped, so it cannot sweep every
 * disk incident away; ignored mounts (IgnoredMounts) are left out, so the sweep resolves theirs.
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
        $openIncidents = null;

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

            if ($severity === null && $used * 100 > $clearBelowPercent * $size) {
                $openIncidents ??= $this->openIncidents($server);
                $observations[$key] = $this->inHoldBand($openIncidents->get($key), $key, $mount, $used, $available);

                continue;
            }

            $observations[$key] = $severity === null
                ? IncidentObservation::clear($key)
                : $this->usage($key, $severity, $mount, $used, $available);
        }

        return array_values($observations);
    }

    /**
     * Usage under the warning threshold but not yet `clear_margin_percent` under it.
     */
    private function inHoldBand(?ServerIncident $open, string $key, string $mount, int $used, int $available): IncidentObservation
    {
        return match (true) {
            ! $open instanceof ServerIncident => IncidentObservation::hold($key),
            ($open->context['unresponsive'] ?? false) === true => IncidentObservation::clear($key),
            $open->severity === IncidentSeverity::Critical => $this->usage($key, IncidentSeverity::Warning, $mount, $used, $available),
            default => IncidentObservation::hold($key),
        };
    }

    private function usage(string $key, IncidentSeverity $severity, string $mount, int $used, int $available): IncidentObservation
    {
        $size = $used + $available;

        return IncidentObservation::raise(
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

    /**
     * The server's open disk incidents by key, read once and only when a mount sits in the hold band.
     *
     * @return Collection<string, ServerIncident>
     */
    private function openIncidents(Server $server): Collection
    {
        return ServerIncident::query()
            ->where('server_id', $server->id)
            ->open()
            ->where('key', 'like', 'disk:%')
            ->get(['key', 'severity', 'context'])
            ->keyBy('key');
    }
}
