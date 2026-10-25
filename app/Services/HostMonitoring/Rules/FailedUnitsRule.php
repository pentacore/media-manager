<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Services\HostMonitoring\AgentReport;

/**
 * Raises `failed_units` while systemd lists failed units. A host without systemd (Unraid) reports null, which says
 * nothing either way.
 */
final readonly class FailedUnitsRule implements ReportRule
{
    private const string KEY = 'failed_units';

    public function ownsKey(string $key): bool
    {
        return $key === self::KEY;
    }

    /**
     * @return list<IncidentObservation>|null
     */
    public function evaluate(Server $server, AgentReport $agentReport): ?array
    {
        if ($agentReport->failedUnits === null) {
            return null;
        }

        if ($agentReport->failedUnits === []) {
            return [IncidentObservation::clear(self::KEY)];
        }

        $units = $agentReport->failedUnits;
        sort($units);

        return [IncidentObservation::raise(self::KEY, IncidentSeverity::Warning, 'Failed systemd units', implode(', ', $units), ['units' => $units])];
    }
}
