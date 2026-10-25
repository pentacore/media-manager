<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Events\ServerIncidentChanged;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Notifications\HostIncidentEscalated;
use App\Notifications\HostIncidentNotification;
use App\Notifications\HostIncidentOpened;
use App\Notifications\HostIncidentResolved;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The incident state machine for one server and key: open, escalate once, resolve after consecutive clears.
 * Every call locks the server row and then the incident row in its own transaction, so two writers for one
 * server queue up whoever called. Admins are notified and the browser is told only when something worth a
 * message changed, never for a detail-only update.
 *
 * A resolve is broadcast at once, but its "resolved" push is held: the minute sweep (notifyHeldResolutions)
 * sends it once the incident stayed resolved for `resolve_notify_delay_minutes`. A key that recurs before that
 * push went out reopens the same incident silently, so a flapping problem pushes once; the reopen pushes only
 * when its severity rises above the one admins were told about. A recurrence after the push opens a new
 * incident with its own "opened" push: admins who were told it resolved always hear that it is back.
 */
final readonly class IncidentTracker
{
    private const int TITLE_LIMIT = 255;

    public function __construct(private AdminNotifier $adminNotifier) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function observe(Server $server, string $key, IncidentSeverity $severity, string $title, ?string $detail, array $context = []): ServerIncident
    {
        $title = Str::limit($title, self::TITLE_LIMIT - 1, '…');

        return DB::transaction(function () use ($server, $key, $severity, $title, $detail, $context): ServerIncident {
            $this->lockServer($server);
            $incident = $this->lockOpen($server, $key);

            if (! $incident instanceof ServerIncident) {
                $unannounced = $this->lockUnannouncedResolution($server, $key);

                if ($unannounced instanceof ServerIncident) {
                    return $this->reopen($server, $unannounced, $severity, $title, $detail, $context);
                }

                $incident = ServerIncident::query()->create([
                    'server_id' => $server->id,
                    'key' => $key,
                    'severity' => $severity,
                    'status' => IncidentStatus::Open,
                    'title' => $title,
                    'detail' => $detail,
                    'context' => $context,
                    'opened_at' => now(),
                    'last_seen_at' => now(),
                    'clear_streak' => 0,
                    'notified_severity' => $severity,
                ]);

                $this->afterCommit($server, $incident, HostIncidentOpened::class);

                return $incident;
            }

            $previous = $incident->severity;
            $incident->fill([
                'title' => $title,
                'detail' => $detail,
                'context' => $context,
                'last_seen_at' => now(),
                'clear_streak' => 0,
                'severity' => $severity,
            ]);

            $escalate = $severity->rank() > $previous->rank()
                && $severity->rank() > ($incident->notified_severity?->rank() ?? 0);

            if ($severity->rank() > $previous->rank()) {
                $incident->escalated_at ??= now();
            }

            if ($escalate) {
                $incident->notified_severity = $severity;
            }

            $incident->save();

            if ($escalate) {
                $this->afterCommit($server, $incident, HostIncidentEscalated::class);
            } elseif ($severity !== $previous) {
                $this->afterCommit($server, $incident, null);
            }

            return $incident;
        });
    }

    /**
     * Counts one clean evaluation against the open incident and resolves it at the required streak.
     * Returns the incident when one was open, null when there was nothing to clear.
     */
    public function clear(Server $server, string $key, ?int $requiredClears = null): ?ServerIncident
    {
        $requiredClears ??= (int) config('mediamanager.monitoring.incident_clear_streak');

        return DB::transaction(function () use ($server, $key, $requiredClears): ?ServerIncident {
            $this->lockServer($server);
            $incident = $this->lockOpen($server, $key);

            if (! $incident instanceof ServerIncident) {
                return null;
            }

            $incident->clear_streak++;

            if ($incident->clear_streak < $requiredClears) {
                $incident->save();

                return $incident;
            }

            $incident->status = IncidentStatus::Resolved;
            $incident->resolved_at = now();
            $incident->resolve_notified_at = null;
            $incident->save();

            $this->afterCommit($server, $incident, null);

            return $incident;
        });
    }

    /**
     * Sends the held "resolved" push of every incident that stayed resolved for the notify delay. Each one is
     * re-read under the server and incident locks, so an incident that reopened meanwhile is skipped.
     *
     * @return int pushes sent
     */
    public function notifyHeldResolutions(): int
    {
        $sent = 0;

        $this->unannouncedResolutions()
            ->orderBy('id')
            ->pluck('server_id', 'id')
            ->each(function (int $serverId, int $incidentId) use (&$sent): void {
                $sent += $this->notifyHeldResolution($serverId, $incidentId) ? 1 : 0;
            });

        return $sent;
    }

    /**
     * Brings a resolved incident whose "resolved" push is still held back as open with a fresh clear streak. Admins
     * were told it opened and never that it resolved, so only a severity above the one they know is pushed, as an
     * escalation.
     *
     * @param  array<string, mixed>  $context
     */
    private function reopen(Server $server, ServerIncident $incident, IncidentSeverity $severity, string $title, ?string $detail, array $context): ServerIncident
    {
        $previous = $incident->severity;
        $escalate = $severity->rank() > ($incident->notified_severity?->rank() ?? 0);

        $incident->fill([
            'status' => IncidentStatus::Open,
            'resolved_at' => null,
            'title' => $title,
            'detail' => $detail,
            'context' => $context,
            'last_seen_at' => now(),
            'clear_streak' => 0,
            'severity' => $severity,
        ]);

        if ($severity->rank() > $previous->rank()) {
            $incident->escalated_at ??= now();
        }

        if ($escalate) {
            $incident->notified_severity = $severity;
        }

        $incident->save();

        $this->afterCommit($server, $incident, $escalate ? HostIncidentEscalated::class : null);

        return $incident;
    }

    private function lockServer(Server $server): void
    {
        Server::query()->whereKey($server->id)->lockForUpdate()->value('id');
    }

    private function notifyHeldResolution(int $serverId, int $incidentId): bool
    {
        return DB::transaction(function () use ($serverId, $incidentId): bool {
            $server = Server::query()->whereKey($serverId)->lockForUpdate()->first();

            if ($server === null) {
                return false;
            }

            $incident = $this->unannouncedResolutions()->whereKey($incidentId)->lockForUpdate()->first();

            if (! $incident instanceof ServerIncident) {
                return false;
            }

            $incident->resolve_notified_at = now();
            $incident->save();

            $incident->setRelation('server', $server);

            $hostIncidentResolved = HostIncidentResolved::fromIncident($incident);

            DB::afterCommit(fn () => $this->notifyAdmins($hostIncidentResolved));

            return true;
        });
    }

    /**
     * Resolved incidents whose "resolved" push is due: still unsent after the notify delay.
     *
     * @return Builder<ServerIncident>
     */
    private function unannouncedResolutions(): Builder
    {
        return ServerIncident::query()
            ->where('status', IncidentStatus::Resolved->value)
            ->whereNull('resolve_notified_at')
            ->where('resolved_at', '<=', now()->subMinutes((int) config('mediamanager.monitoring.resolve_notify_delay_minutes')));
    }

    /**
     * The newest resolved incident for the key whose "resolved" push has not gone out yet.
     */
    private function lockUnannouncedResolution(Server $server, string $key): ?ServerIncident
    {
        return ServerIncident::query()
            ->where('server_id', $server->id)
            ->where('key', $key)
            ->where('status', IncidentStatus::Resolved->value)
            ->whereNull('resolve_notified_at')
            ->latest('resolved_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function lockOpen(Server $server, string $key): ?ServerIncident
    {
        return ServerIncident::query()
            ->where('server_id', $server->id)
            ->where('key', $key)
            ->open()
            ->lockForUpdate()
            ->first();
    }

    /**
     * Queues the browser broadcast, and the admin notification when a class is given, to run once the
     * transaction commits, so neither announces a state that rolled back. The broadcast goes first and a
     * failing channel (mail down) is reported, not thrown, so it cannot take the broadcast or the callbacks
     * queued after this one down with it.
     *
     * @param  class-string<HostIncidentNotification>|null  $notification
     */
    private function afterCommit(Server $server, ServerIncident $incident, ?string $notification): void
    {
        $incident->setRelation('server', $server);
        $message = $notification === null ? null : $notification::fromIncident($incident);
        $serverIncidentChanged = new ServerIncidentChanged($incident);

        DB::afterCommit(function () use ($message, $serverIncidentChanged): void {
            event($serverIncidentChanged);

            if ($message instanceof HostIncidentNotification) {
                $this->notifyAdmins($message);
            }
        });
    }

    /**
     * A failing channel (mail down) is reported, not thrown, so it cannot take down the callbacks queued after it.
     */
    private function notifyAdmins(HostIncidentNotification $message): void
    {
        try {
            $this->adminNotifier->send($message);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
