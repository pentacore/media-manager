<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Models\Server;
use App\Services\HostMonitoring\IncidentTracker;
use App\Support\OpsHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Raises a critical `silent` incident for every enrolled server whose agent has not reported for
 * `silent_after_intervals` of its report intervals. It is a scheduled sweep, not a ReportRule: a silent agent sends
 * nothing to evaluate. The next processed report resolves the incident (ProcessAgentReport clears `silent`).
 *
 * Two outages of the platform itself must not read as silent agents. After a scheduler gap the clock restarts from
 * the moment the scheduler resumed, and the sweep skips a tick whose heartbeat is stale. When every agent that ever
 * reported (two or more) fell silent within one two-minute window, the ingress in front of the platform is down
 * rather than the hosts: the outside watcher reports that, so nothing opens here. A partial outage still alerts.
 */
final readonly class AgentSilentRule
{
    private const string KEY = 'silent';

    /** The widest spread of last reports that still reads as every agent dropping at once. */
    private const int MASS_SILENCE_WINDOW_SECONDS = 120;

    public function __construct(private IncidentTracker $incidentTracker) {}

    /**
     * @return int incidents raised or refreshed
     */
    public function sweep(): int
    {
        $schedulerAge = OpsHeartbeat::ageInSeconds(OpsHeartbeat::SCHEDULER);

        if ($schedulerAge === null || $schedulerAge > (int) config('mediamanager.monitoring.platform_gap_seconds')) {
            return 0;
        }

        $resumedAt = OpsHeartbeat::recordedAt(OpsHeartbeat::SCHEDULER_RESUMED);
        $servers = Server::query()->whereNotNull('enrolled_at')->get(['id', 'name', 'enrolled_at', 'last_report_at', 'report_interval_seconds']);
        $silent = $servers->filter(fn (Server $server): bool => $this->isSilent($server, $resumedAt));

        if ($silent->isEmpty()) {
            return 0;
        }

        if ($this->everyAgentDroppedAtOnce($servers, $silent)) {
            Log::warning('AgentSilentRule: every agent went silent at once; assuming platform ingress is down', [
                'servers' => $silent->pluck('name')->all(),
                'last_report_at' => $silent->map(fn (Server $server): ?string => $server->last_report_at?->toIso8601String())->values()->all(),
            ]);

            return 0;
        }

        return $silent->sum(fn (Server $server): int => $this->raise($server->id, $resumedAt) ? 1 : 0);
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @param  Collection<int, Server>  $silent
     */
    private function everyAgentDroppedAtOnce(Collection $servers, Collection $silent): bool
    {
        $reported = $servers->filter(fn (Server $server): bool => $server->last_report_at instanceof CarbonImmutable);

        if ($reported->count() < 2 || $reported->contains(fn (Server $server): bool => $silent->doesntContain($server))) {
            return false;
        }

        $timestamps = $reported->map(fn (Server $server): int => (int) $server->last_report_at?->getTimestamp());

        return $timestamps->max() - $timestamps->min() <= self::MASS_SILENCE_WINDOW_SECONDS;
    }

    /**
     * Re-reads the server under its row lock, so a report that landed since the sweep loaded it is not overruled.
     */
    private function raise(int $serverId, ?CarbonImmutable $resumedAt): bool
    {
        return DB::transaction(function () use ($serverId, $resumedAt): bool {
            $server = Server::query()->whereKey($serverId)->lockForUpdate()->first();

            if ($server === null || ! $this->isSilent($server, $resumedAt)) {
                return false;
            }

            $lastSeen = $server->last_report_at ?? $server->enrolled_at;

            $this->incidentTracker->observe(
                $server,
                self::KEY,
                IncidentSeverity::Critical,
                'Agent silent',
                sprintf(
                    'No report since %s (%s).',
                    $server->last_report_at?->toIso8601String() ?? 'enrollment',
                    $lastSeen?->diffForHumans(),
                ),
                ['last_report_at' => $server->last_report_at?->toIso8601String()],
            );

            return true;
        });
    }

    /**
     * Silent once more than the allowed intervals passed since the last report (or enrollment), counting from the
     * scheduler's resume when that is later: a platform outage is not the agent's silence.
     */
    private function isSilent(Server $server, ?CarbonImmutable $resumedAt): bool
    {
        $since = $server->last_report_at ?? $server->enrolled_at;

        if (! $since instanceof CarbonImmutable) {
            return false;
        }

        if ($resumedAt instanceof CarbonImmutable && $resumedAt->greaterThan($since)) {
            $since = $resumedAt;
        }

        $allowedSeconds = (int) config('mediamanager.monitoring.silent_after_intervals') * $server->report_interval_seconds;

        return now()->getTimestamp() - $since->getTimestamp() > $allowedSeconds;
    }
}
