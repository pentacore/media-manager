<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Services\HostMonitoring\IncidentTracker;
use App\Support\OpsHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Raises a critical `silent` incident for every enrolled server whose agent has not reported for
 * `silent_after_intervals` of its report intervals. It is a scheduled sweep, not a ReportRule: a silent agent sends
 * nothing to evaluate. The next processed report resolves the incident (ProcessAgentReport clears `silent`).
 *
 * Two outages of the platform itself must not read as silent agents. After a scheduler gap the clock restarts from
 * the moment the scheduler resumed, and the sweep skips a tick whose heartbeat is stale. An ingress outage (NPM in
 * front of the platform) stops every live agent at once: their last reports sit within two minutes of each other and
 * even the newest is overdue. The outside watcher reports that, so nothing opens here. Agents report at their own
 * offsets and cross the threshold on different ticks, so the test looks at the live fleet, not only the candidates,
 * on every tick. A partial outage still alerts, because an agent that keeps reporting makes the newest report current.
 * The tick that sees an ingress episode end records that moment as an ingress resume, which counts like a scheduler
 * resume.
 *
 * Agents back off on platform errors (up to `agent_max_backoff_seconds`, jittered), so after either kind of resume
 * they come back at staggered times. An agent whose silence started before the resume is allowed that backoff plus
 * one more interval on top of the usual threshold before it reads as silent.
 */
final readonly class AgentSilentRule
{
    private const string KEY = 'silent';

    /** The widest spread of last reports that still reads as every agent dropping at once. */
    private const int MASS_SILENCE_WINDOW_SECONDS = 120;

    /** Slack past one report interval before the newest report counts as overdue (network and queue delays). */
    private const int OVERDUE_SLACK_SECONDS = 30;

    /** Set while a mass silence is being suppressed, so the warning is logged once per episode. */
    private const string SUPPRESSION_CACHE_KEY = 'host-monitoring:silent-suppressed';

    /** When the last ingress episode ended (a Unix timestamp). */
    private const string INGRESS_RESUMED_CACHE_KEY = 'host-monitoring:ingress-resumed';

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

        $servers = Server::query()
            ->select(['id', 'name', 'enrolled_at', 'last_report_at', 'report_interval_seconds'])
            ->whereNotNull('enrolled_at')
            ->withExists(['incidents as silent_incident_exists' => fn (Builder $incidents): Builder => $incidents->where('status', IncidentStatus::Open->value)->where('key', self::KEY)])
            ->get();
        $liveFleet = $this->liveFleet($servers);

        if ($this->ingressLooksDown($liveFleet)) {
            if (Cache::add(self::SUPPRESSION_CACHE_KEY, now()->toIso8601String())) {
                Log::warning('AgentSilentRule: every agent went silent at once; assuming platform ingress is down', [
                    'servers' => $liveFleet->pluck('name')->values()->all(),
                    'last_report_at' => $liveFleet->map(fn (Server $server): ?string => $server->last_report_at?->toIso8601String())->values()->all(),
                ]);
            }

            return 0;
        }

        if (Cache::pull(self::SUPPRESSION_CACHE_KEY) !== null) {
            Cache::forever(self::INGRESS_RESUMED_CACHE_KEY, now()->getTimestamp());
        }

        $resumedAt = $this->resumedAt();

        return $servers
            ->filter(fn (Server $server): bool => $this->isSilent($server, $resumedAt))
            ->sum(fn (Server $server): int => $this->raise($server->id, $resumedAt) ? 1 : 0);
    }

    /**
     * The later of the scheduler's last resume and the end of the last ingress episode.
     */
    private function resumedAt(): ?CarbonImmutable
    {
        $schedulerResumedAt = OpsHeartbeat::recordedAt(OpsHeartbeat::SCHEDULER_RESUMED);
        $ingressResumedAt = Cache::get(self::INGRESS_RESUMED_CACHE_KEY);
        $ingressResumedAt = is_numeric($ingressResumedAt)
            ? CarbonImmutable::createFromTimestamp((int) $ingressResumedAt, now()->getTimezone())
            : null;

        if (! $schedulerResumedAt instanceof CarbonImmutable || ! $ingressResumedAt instanceof CarbonImmutable) {
            return $schedulerResumedAt ?? $ingressResumedAt;
        }

        return $schedulerResumedAt->max($ingressResumedAt);
    }

    /**
     * The agents that were reporting until recently: they have reported, are not already known to be silent, and
     * their last report is within their own silence threshold of the newest report in the fleet. A long-dead server
     * would otherwise stretch the spread and disable the ingress test for everyone.
     *
     * @param  Collection<int, Server>  $servers
     * @return Collection<int, Server>
     */
    private function liveFleet(Collection $servers): Collection
    {
        $reported = $servers->filter(fn (Server $server): bool => $server->last_report_at instanceof CarbonImmutable);
        $newest = $reported->max(fn (Server $server): int => (int) $server->last_report_at?->getTimestamp());

        return $reported
            ->reject(fn (Server $server): bool => (bool) $server->silent_incident_exists)
            ->filter(fn (Server $server): bool => (int) $server->last_report_at?->getTimestamp() >= $newest - $this->allowedSilenceSeconds($server))
            ->values();
    }

    /**
     * Two or more live agents whose last reports fall within one two-minute window, with even the newest overdue.
     *
     * @param  Collection<int, Server>  $liveFleet
     */
    private function ingressLooksDown(Collection $liveFleet): bool
    {
        if ($liveFleet->count() < 2) {
            return false;
        }

        $timestamps = $liveFleet->map(fn (Server $server): int => (int) $server->last_report_at?->getTimestamp());

        if ($timestamps->max() - $timestamps->min() > self::MASS_SILENCE_WINDOW_SECONDS) {
            return false;
        }

        /** @var Server $newest */
        $newest = $liveFleet->sortByDesc(fn (Server $server): int => (int) $server->last_report_at?->getTimestamp())->first();

        return now()->getTimestamp() - (int) $newest->last_report_at?->getTimestamp() > $newest->report_interval_seconds + self::OVERDUE_SLACK_SECONDS;
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
     * Silent once more than the allowed intervals passed since the last report (or enrollment). When a platform
     * resume is later, the clock counts from the resume instead and also allows the agent's backoff and one more
     * interval: a platform outage is not the agent's silence, and the agent may still be waiting out its retry.
     */
    private function isSilent(Server $server, ?CarbonImmutable $resumedAt): bool
    {
        $since = $server->last_report_at ?? $server->enrolled_at;

        if (! $since instanceof CarbonImmutable) {
            return false;
        }

        $allowedSeconds = $this->allowedSilenceSeconds($server);

        if ($resumedAt instanceof CarbonImmutable && $resumedAt->greaterThan($since)) {
            $since = $resumedAt;
            $allowedSeconds += (int) config('mediamanager.monitoring.agent_max_backoff_seconds') + $server->report_interval_seconds;
        }

        return now()->getTimestamp() - $since->getTimestamp() > $allowedSeconds;
    }

    private function allowedSilenceSeconds(Server $server): int
    {
        return (int) config('mediamanager.monitoring.silent_after_intervals') * $server->report_interval_seconds;
    }
}
