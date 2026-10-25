<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerIncidentIndexRequest;
use App\Models\Server;
use App\Models\ServerIncident;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class ServerIncidentController extends Controller
{
    public function __invoke(ServerIncidentIndexRequest $serverIncidentIndexRequest): Response
    {
        $validated = $serverIncidentIndexRequest->validated();
        $serverId = isset($validated['server']) ? (int) $validated['server'] : null;

        $lengthAwarePaginator = $this->incidentQuery($serverId)
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Servers/Incidents', [
            'incidents' => [
                'data' => $lengthAwarePaginator->getCollection()->map($this->row(...))->all(),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            'servers' => Server::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Server $server): array => ['id' => $server->id, 'name' => $server->name])
                ->all(),
            'filters' => ['server' => $serverId],
        ]);
    }

    /**
     * Open incidents first, then resolved, newest first within each.
     *
     * @return Builder<ServerIncident>
     */
    private function incidentQuery(?int $serverId): Builder
    {
        return ServerIncident::query()
            ->with('server:id,name')
            ->when($serverId !== null, static fn (Builder $query) => $query->where('server_id', $serverId))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->latest('opened_at')
            ->orderByDesc('id');
    }

    /**
     * @return array{id: int, server_id: int, server_name: string, key: string, severity: string, severity_label: string, status: string, title: string, detail: ?string, opened_at: string, last_seen_at: string, resolved_at: ?string}
     */
    private function row(ServerIncident $serverIncident): array
    {
        return [
            'id' => $serverIncident->id,
            'server_id' => $serverIncident->server_id,
            'server_name' => $serverIncident->server->name,
            'key' => $serverIncident->key,
            'severity' => $serverIncident->severity->value,
            'severity_label' => $serverIncident->severity->label(),
            'status' => $serverIncident->status->value,
            'title' => $serverIncident->title,
            'detail' => $serverIncident->detail,
            'opened_at' => $serverIncident->opened_at->toIso8601String(),
            'last_seen_at' => $serverIncident->last_seen_at->toIso8601String(),
            'resolved_at' => $serverIncident->resolved_at?->toIso8601String(),
        ];
    }
}
