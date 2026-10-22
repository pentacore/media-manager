<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Services\HostMonitoring\AgentReport;
use Carbon\CarbonImmutable;

/**
 * Raises `oom` while the kernel's OOM kill counter rose within the configured minutes. The counter is cumulative since
 * boot, so the rule keeps the last total in `rule_state.oom`: the first report and a counter that went down (a reboot)
 * only set the baseline and give no verdict. A reboot keeps the time and size of the last increase, so an incident from
 * kills before it still clears on the usual schedule.
 */
final readonly class OomRule implements ReportRule
{
    private const string KEY = 'oom';

    public function ownsKey(string $key): bool
    {
        return $key === self::KEY;
    }

    /**
     * @return list<IncidentObservation>
     */
    public function evaluate(Server $server, AgentReport $agentReport): array
    {
        /** @var array{total?: int, last_increase_at?: ?string, last_delta?: int} $state */
        $state = $server->rule_state[self::KEY] ?? [];
        $current = $agentReport->oomKillsTotal;
        $previous = $state['total'] ?? null;
        $lastIncreaseAt = $state['last_increase_at'] ?? null;
        $lastDelta = $state['last_delta'] ?? 0;

        if ($previous !== null && $current > $previous) {
            $lastIncreaseAt = $agentReport->receivedAt->toIso8601String();
            $lastDelta = $current - $previous;
        }

        $server->rule_state = [
            ...$server->rule_state,
            self::KEY => ['total' => $current, 'last_increase_at' => $lastIncreaseAt, 'last_delta' => $lastDelta],
        ];

        if ($previous === null || $current < $previous) {
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
}
