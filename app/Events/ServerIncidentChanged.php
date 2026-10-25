<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ServerIncident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server incident opened, changed severity or resolved. Detail-only updates never fire it.
 */
class ServerIncidentChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public int $serverId;

    public int $incidentId;

    public string $key;

    public string $status;

    public string $severity;

    public string $title;

    public string $occurredAt;

    public function __construct(ServerIncident $incident)
    {
        $this->serverId = $incident->server_id;
        $this->incidentId = $incident->id;
        $this->key = $incident->key;
        $this->status = $incident->status->value;
        $this->severity = $incident->severity->value;
        $this->title = $incident->title;
        $this->occurredAt = now()->toIso8601String();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.servers');
    }

    public function broadcastAs(): string
    {
        return 'ServerIncidentChanged';
    }

    /**
     * @return array{server_id: int, incident_id: int, key: string, status: string, severity: string, title: string, occurred_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'incident_id' => $this->incidentId,
            'key' => $this->key,
            'status' => $this->status,
            'severity' => $this->severity,
            'title' => $this->title,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
