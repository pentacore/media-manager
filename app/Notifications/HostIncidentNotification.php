<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\IncidentSeverity;
use App\Models\ServerIncident;
use App\Services\Notifications\PreferenceResolver;
use App\Services\Notifications\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared shape of the server incident notifications. The incident is snapshotted at construction, so the
 * message reads the same whenever the (unqueued) notification is rendered and a later change never alters it.
 */
abstract class HostIncidentNotification extends Notification
{
    use Queueable;

    final public function __construct(
        public readonly int $serverId,
        public readonly string $serverName,
        public readonly int $incidentId,
        public readonly string $title,
        public readonly ?string $detail,
        public readonly IncidentSeverity $severity,
        public readonly CarbonImmutable $openedAt,
    ) {}

    public static function fromIncident(ServerIncident $incident): static
    {
        return new static(
            serverId: $incident->server_id,
            serverName: $incident->server->name,
            incidentId: $incident->id,
            title: $incident->title,
            detail: $incident->detail,
            severity: $incident->severity,
            openedAt: $incident->opened_at,
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return resolve(PreferenceResolver::class)->channelsFor($notifiable, static::class, $this->severityKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'service' => 'system',
            'title' => $this->pushTitle(),
            'message' => $this->body(),
            'link' => $this->link(),
            'server_id' => $this->serverId,
            'incident_id' => $this->incidentId,
            'severity' => $this->severity->value,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(severity: $this->severityKey(), title: $this->pushTitle(), body: $this->body(), url: url($this->link()));
    }

    public function link(): string
    {
        return sprintf('%s#incident-%d', route('admin.servers.show', ['server' => $this->serverId], absolute: false), $this->incidentId);
    }

    /**
     * @return 'info'|'warning'|'error'
     */
    abstract protected function severityKey(): string;

    abstract protected function pushTitle(): string;

    abstract protected function body(): string;
}
