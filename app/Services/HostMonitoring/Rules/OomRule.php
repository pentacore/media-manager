<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Services\HostMonitoring\AgentReport;
use Carbon\CarbonImmutable;

/**
 * Raises `oom` while the kernel's OOM kill counter rose within the configured minutes. The counter is cumulative since
 * boot, so the rule keeps the last total and the host's boot time (receive time minus uptime) in `rule_state.oom`.
 * The first report and a reboot only set the baseline and give no verdict. A reboot shows as a boot time that moved
 * by more than the tolerance, or, when the agent sent no uptime, as a counter that went down. A reboot keeps the time
 * and size of the last increase, so an incident from kills before it still clears on the usual schedule.
 */
final readonly class OomRule implements ReportRule
{
    private const string KEY = 'oom';

    /** How far two reports' boot times may differ without counting as a reboot (network and queue delays). */
    private const int BOOT_TOLERANCE_SECONDS = 300;

    public function ownsKey(string $key): bool
    {
        return $key === self::KEY;
    }

    /**
     * @return list<IncidentObservation>
     */
    public function evaluate(Server $server, AgentReport $agentReport): array
    {
        /** @var array{total?: int, booted_at?: ?string, last_increase_at?: ?string, last_delta?: int} $state */
        $state = $server->rule_state[self::KEY] ?? [];
        $current = $agentReport->oomKillsTotal;
        $previous = $state['total'] ?? null;
        $storedBootedAt = $state['booted_at'] ?? null;
        $bootedAt = $agentReport->host['uptime_seconds'] === null
            ? $storedBootedAt
            : $agentReport->receivedAt->subSeconds($agentReport->host['uptime_seconds'])->toIso8601String();
        $lastIncreaseAt = $state['last_increase_at'] ?? null;
        $lastDelta = $state['last_delta'] ?? 0;

        $isBaseline = $previous === null || $current < $previous || $this->rebooted($storedBootedAt, $bootedAt);

        if (! $isBaseline && $current > $previous) {
            $lastIncreaseAt = $agentReport->receivedAt->toIso8601String();
            $lastDelta = $current - $previous;
        }

        $server->rule_state = [
            ...$server->rule_state,
            self::KEY => [
                'total' => $current,
                'booted_at' => $isBaseline || $storedBootedAt === null ? $bootedAt : $storedBootedAt,
                'last_increase_at' => $lastIncreaseAt,
                'last_delta' => $lastDelta,
            ],
        ];

        if ($isBaseline) {
            return [IncidentObservation::hold(self::KEY)];
        }

        $clearAfterMinutes = (int) config('mediamanager.monitoring.oom.clear_after_minutes');

        if ($lastIncreaseAt === null || ! $agentReport->receivedAt->lessThan(CarbonImmutable::parse($lastIncreaseAt)->addMinutes($clearAfterMinutes))) {
            return [IncidentObservation::clear(self::KEY)];
        }

        return [IncidentObservation::raise(
            self::KEY,
            IncidentSeverity::Warning,
            'Out-of-memory kills',
            sprintf('The kernel killed %d process(es) since the previous report (%d in total).', $lastDelta, $current),
            ['oom_kills_total' => $current, 'last_delta' => $lastDelta, 'last_increase_at' => $lastIncreaseAt],
        )];
    }

    private function rebooted(?string $storedBootedAt, ?string $bootedAt): bool
    {
        if ($storedBootedAt === null || $bootedAt === null) {
            return false;
        }

        return abs(CarbonImmutable::parse($bootedAt)->diffInSeconds(CarbonImmutable::parse($storedBootedAt))) > self::BOOT_TOLERANCE_SECONDS;
    }
}
