<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Services\HostMonitoring\AgentReport;
use Carbon\CarbonImmutable;

/**
 * Raises `restarts:{name}` when a container's Docker restart count grew by the threshold within the configured window.
 * `rule_state.restarts` keeps each reported container's `[ISO time, restart_count]` points inside the window; a count
 * below the last point means the container was recreated and starts over. Without Docker (null) nothing is judged and
 * the state is kept; a container that is no longer reported loses its state and the engine's sweep clears its incident.
 */
final readonly class ContainerRestartRule implements ReportRule
{
    private const string STATE_KEY = 'restarts';

    public function ownsKey(string $key): bool
    {
        return str_starts_with($key, 'restarts:');
    }

    /**
     * @return list<IncidentObservation>|null
     */
    public function evaluate(Server $server, AgentReport $agentReport): ?array
    {
        if ($agentReport->containers === null) {
            return null;
        }

        $windowMinutes = (int) config('mediamanager.monitoring.restarts.window_minutes');
        $threshold = (int) config('mediamanager.monitoring.restarts.threshold');
        $windowStart = $agentReport->receivedAt->subMinutes($windowMinutes);
        /** @var array<string, list<array{0: string, 1: int}>> $previousState */
        $previousState = $server->rule_state[self::STATE_KEY] ?? [];
        $state = [];
        $observations = [];

        foreach ($agentReport->containers as $container) {
            $name = $container['name'];
            $key = sprintf('restarts:%s', $name);
            $count = $container['restart_count'];

            $points = array_values(array_filter(
                $previousState[$name] ?? [],
                static fn (array $point): bool => ! CarbonImmutable::parse($point[0])->lessThan($windowStart),
            ));

            if ($points !== [] && $count < array_last($points)[1]) {
                $points = [];
            }

            $points[] = [$agentReport->receivedAt->toIso8601String(), $count];
            $state[$name] = $points;
            $increase = $count - min(array_column($points, 1));

            $observations[$key] = $increase >= $threshold
                ? IncidentObservation::raise(
                    $key,
                    IncidentSeverity::Critical,
                    sprintf('Container %s is restart-looping', $name),
                    sprintf('%d restarts in the last %d minutes (state: %s).', $increase, $windowMinutes, $container['state']),
                    ['name' => $name, 'state' => $container['state'], 'health' => $container['health'], 'restart_count' => $count, 'increase' => $increase],
                )
                : IncidentObservation::clear($key);
        }

        $server->rule_state = [...$server->rule_state, self::STATE_KEY => $state];

        return array_values($observations);
    }
}
