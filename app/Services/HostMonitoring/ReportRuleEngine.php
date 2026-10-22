<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Services\HostMonitoring\Rules\CheckStatusRule;
use App\Services\HostMonitoring\Rules\DiskUsageRule;
use App\Services\HostMonitoring\Rules\FailedUnitsRule;
use App\Services\HostMonitoring\Rules\IncidentObservation;
use App\Services\HostMonitoring\Rules\ReportRule;

/**
 * Runs every report rule over one in-order report and applies what they observed through the IncidentTracker.
 * After each rule, open incidents the rule owns but did not name are cleared: their subject vanished from the report.
 * Callers hold the server row lock; stateful rules may change `$server->rule_state`, which the caller saves.
 */
final readonly class ReportRuleEngine
{
    /** @var list<ReportRule> */
    private array $rules;

    public function __construct(private IncidentTracker $incidentTracker)
    {
        $this->rules = [
            new CheckStatusRule,
            new DiskUsageRule,
            new FailedUnitsRule,
        ];
    }

    public function evaluate(Server $server, AgentReport $agentReport): void
    {
        /** @var list<string> $openKeys */
        $openKeys = ServerIncident::query()->where('server_id', $server->id)->open()->pluck('key')->all();

        foreach ($this->rules as $rule) {
            $observations = $rule->evaluate($server, $agentReport);

            if ($observations === null) {
                continue;
            }

            $namedKeys = [];

            foreach ($observations as $observation) {
                $namedKeys[$observation->key] = true;
                $this->apply($server, $observation);
            }

            foreach ($openKeys as $openKey) {
                if ($rule->ownsKey($openKey) && ! isset($namedKeys[$openKey])) {
                    $this->incidentTracker->clear($server, $openKey);
                }
            }
        }
    }

    private function apply(Server $server, IncidentObservation $observation): void
    {
        if ($observation->outcome === IncidentObservation::CLEAR) {
            $this->incidentTracker->clear($server, $observation->key);

            return;
        }

        if ($observation->outcome === IncidentObservation::RAISE && $observation->severity instanceof IncidentSeverity) {
            $this->incidentTracker->observe($server, $observation->key, $observation->severity, $observation->title, $observation->detail, $observation->context);
        }
    }
}
