<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\HostCheckStatus;
use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Services\HostMonitoring\AgentReport;

/**
 * Turns each enabled check's reported status into its `check:{uuid}` incident. An enabled check the report lacks is
 * held (the agent may not have fetched the new config yet); disabled and deleted checks are not observed, so the
 * engine's sweep resolves what they left open.
 */
final readonly class CheckStatusRule implements ReportRule
{
    public function ownsKey(string $key): bool
    {
        return str_starts_with($key, 'check:');
    }

    /**
     * @return list<IncidentObservation>
     */
    public function evaluate(Server $server, AgentReport $agentReport): array
    {
        $results = array_column($agentReport->checks, null, 'id');

        return $server->checks()
            ->where('is_enabled', true)
            ->orderBy('id')
            ->get()
            ->map(fn (ServerCheck $serverCheck): IncidentObservation => isset($results[$serverCheck->uuid])
                ? $this->observe($serverCheck, $results[$serverCheck->uuid])
                : IncidentObservation::hold($serverCheck->incidentKey()))
            ->values()
            ->all();
    }

    /**
     * @param  array{id: string, type: string, status: HostCheckStatus, message: ?string, observed: ?array<array-key, mixed>}  $result
     */
    private function observe(ServerCheck $serverCheck, array $result): IncidentObservation
    {
        $key = $serverCheck->incidentKey();
        $label = $serverCheck->label();

        [$severity, $title] = match ($result['status']) {
            HostCheckStatus::Ok => [null, ''],
            HostCheckStatus::Crit => [IncidentSeverity::Critical, $label],
            HostCheckStatus::Warn => [IncidentSeverity::Warning, $label],
            HostCheckStatus::Unsupported => [IncidentSeverity::Warning, sprintf('%s is not supported by this agent', $label)],
            HostCheckStatus::Error => [IncidentSeverity::Warning, sprintf('%s could not run', $label)],
        };

        if ($severity === null) {
            return IncidentObservation::clear($key);
        }

        return IncidentObservation::raise($key, $severity, $title, $result['message'], [
            'check_uuid' => $serverCheck->uuid,
            'type' => $serverCheck->type->value,
            'status' => $result['status']->value,
            'observed' => $result['observed'],
        ]);
    }
}
