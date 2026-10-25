<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\HostCheckStatus;
use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Services\HostMonitoring\AgentReport;

/**
 * Turns each enabled check's reported status into its `check:{uuid}` incident. A check raises only after two non-ok
 * results in a row (a one-off failed db ping is noise), counted per check in `rule_state.check_pending`; an incident
 * already open is refreshed by the next non-ok result at once. An enabled check the report lacks is held and keeps its
 * count (the agent may not have fetched the new config yet); disabled and deleted checks are not observed, so the
 * engine's sweep resolves what they left open and their counts are dropped.
 */
final readonly class CheckStatusRule implements ReportRule
{
    private const string STATE_KEY = 'check_pending';

    /** Consecutive non-ok results a check needs before it opens an incident. */
    private const int RAISE_AFTER = 2;

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
        /** @var array<string, int> $pending */
        $pending = $server->rule_state[self::STATE_KEY] ?? [];
        /** @var array<string, int> $openKeys */
        $openKeys = $server->incidents()->open()->where('key', 'like', 'check:%')->pluck('id', 'key')->all();
        $nextPending = [];
        $observations = [];

        foreach ($server->checks()->where('is_enabled', true)->orderBy('id')->get() as $serverCheck) {
            $key = $serverCheck->incidentKey();

            if (! isset($results[$serverCheck->uuid])) {
                if (isset($pending[$serverCheck->uuid])) {
                    $nextPending[$serverCheck->uuid] = $pending[$serverCheck->uuid];
                }

                $observations[] = IncidentObservation::hold($key);

                continue;
            }

            $result = $results[$serverCheck->uuid];

            if ($result['status'] === HostCheckStatus::Ok) {
                $observations[] = $this->observe($serverCheck, $result);

                continue;
            }

            $nextPending[$serverCheck->uuid] = min(self::RAISE_AFTER, ($pending[$serverCheck->uuid] ?? 0) + 1);

            $observations[] = $nextPending[$serverCheck->uuid] >= self::RAISE_AFTER || isset($openKeys[$key])
                ? $this->observe($serverCheck, $result)
                : IncidentObservation::hold($key);
        }

        $server->rule_state = [...$server->rule_state, self::STATE_KEY => $nextPending];

        return $observations;
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
