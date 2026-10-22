<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Models\ServerMetricSample;
use App\Services\HostMonitoring\AgentReport;
use Illuminate\Support\Number;

/**
 * Raises `memory` when available memory stayed under the configured share of the total for the whole window of 60 s
 * samples: the best reading in the window sets the severity, so a short dip raises nothing. A healthy reading clears at
 * once; a low one without enough history gets no verdict. A report without a memory total is skipped.
 */
final readonly class MemoryPressureRule implements ReportRule
{
    private const string KEY = 'memory';

    public function ownsKey(string $key): bool
    {
        return $key === self::KEY;
    }

    /**
     * @return list<IncidentObservation>|null
     */
    public function evaluate(Server $server, AgentReport $agentReport): ?array
    {
        $total = $agentReport->memoryTotalBytes;

        if ($total <= 0) {
            return null;
        }

        $warningPercent = (int) config('mediamanager.monitoring.memory.warning_available_percent');
        $criticalPercent = (int) config('mediamanager.monitoring.memory.critical_available_percent');
        $windowMinutes = (int) config('mediamanager.monitoring.memory.window_minutes');
        $available = $agentReport->memoryAvailableBytes;

        if ($available * 100 >= $warningPercent * $total) {
            return [IncidentObservation::clear(self::KEY)];
        }

        $samples = ServerMetricSample::query()
            ->where('server_id', $server->id)
            ->where('resolution', ServerMetricSample::MINUTE)
            ->where('recorded_at', '>=', $agentReport->receivedAt->subMinutes($windowMinutes))
            ->where('memory_total_bytes', '>', 0)
            ->get(['memory_used_bytes', 'memory_total_bytes']);

        if ($samples->count() < (int) config('mediamanager.monitoring.memory.min_samples')) {
            return [IncidentObservation::hold(self::KEY)];
        }

        $belowPercent = static fn (int $percent): bool => $samples->every(
            static fn (ServerMetricSample $sample): bool => ($sample->memory_total_bytes - $sample->memory_used_bytes) * 100 < $percent * $sample->memory_total_bytes,
        );

        $severity = match (true) {
            $belowPercent($criticalPercent) => IncidentSeverity::Critical,
            $belowPercent($warningPercent) => IncidentSeverity::Warning,
            default => null,
        };

        if ($severity === null) {
            return [IncidentObservation::clear(self::KEY)];
        }

        $availablePercent = intdiv($available * 100, $total);

        return [IncidentObservation::raise(
            self::KEY,
            $severity,
            sprintf('Memory is low: %d%% available', $availablePercent),
            sprintf(
                '%s available of %s; below %d%% for the last %d minutes.',
                Number::fileSize($available, maxPrecision: 1),
                Number::fileSize($total, maxPrecision: 1),
                $severity === IncidentSeverity::Critical ? $criticalPercent : $warningPercent,
                $windowMinutes,
            ),
            [
                'available_bytes' => $available,
                'total_bytes' => $total,
                'available_percent' => $availablePercent,
                'samples' => $samples->count(),
            ],
        )];
    }
}
