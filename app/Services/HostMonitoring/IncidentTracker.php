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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The incident state machine for one server and key: open, escalate once, resolve after consecutive clears.
 * Callers hold the server row lock; the open incident row is locked here as well. Admins are notified and
 * the browser is told only when something worth a message changed, never for a detail-only update.
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
            $incident = $this->lockOpen($server, $key);

            if (! $incident instanceof ServerIncident) {
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
            $incident->save();

            $this->afterCommit($server, $incident, HostIncidentResolved::class);

            return $incident;
        });
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
     * transaction commits, so neither announces a state that rolled back.
     *
     * @param  class-string<HostIncidentNotification>|null  $notification
     */
    private function afterCommit(Server $server, ServerIncident $incident, ?string $notification): void
    {
        $incident->setRelation('server', $server);
        $message = $notification === null ? null : $notification::fromIncident($incident);
        $serverIncidentChanged = new ServerIncidentChanged($incident);

        DB::afterCommit(function () use ($message, $serverIncidentChanged): void {
            if ($message instanceof HostIncidentNotification) {
                $this->adminNotifier->send($message);
            }

            event($serverIncidentChanged);
        });
    }
}
