<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Models\Server;
use App\Services\HostMonitoring\AgentReport;

/**
 * One family of incident keys evaluated from each in-order agent report. ReportRuleEngine clears every open incident
 * whose key the rule owns but did not name, so a subject that vanished from the report (an unmounted disk, a deleted
 * check) resolves on its own.
 */
interface ReportRule
{
    public function ownsKey(string $key): bool;

    /**
     * @return list<IncidentObservation>|null null = could not evaluate this report; leave owned incidents alone
     */
    public function evaluate(Server $server, AgentReport $agentReport): ?array;
}
