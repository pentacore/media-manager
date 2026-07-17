<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

abstract class BazarrController extends Controller
{
    /**
     * @return array{
     *     connections: list<array{id: int, name: string}>,
     *     selected_connection_id: int|null,
     *     requires_connection_selection: bool
     * }
     */
    protected function connectionProps(Request $request): array
    {
        $connections = $this->activeConnections();
        $requestedConnectionId = $request->integer('connection') ?: null;
        $selectedConnection = null;

        if ($requestedConnectionId !== null) {
            $selectedConnection = $connections->firstWhere('id', $requestedConnectionId);
            abort_unless($selectedConnection instanceof ServiceConnection, 404);
        } elseif ($connections->count() === 1) {
            $selectedConnection = $connections->first();
        }

        return [
            'connections' => $connections
                ->map(static fn (ServiceConnection $serviceConnection): array => [
                    'id' => $serviceConnection->id,
                    'name' => $serviceConnection->name,
                ])
                ->values()
                ->all(),
            'selected_connection_id' => $selectedConnection?->id,
            'requires_connection_selection' => $requestedConnectionId === null && $connections->count() > 1,
        ];
    }

    /**
     * @return Collection<int, ServiceConnection>
     */
    protected function activeConnections(): Collection
    {
        return ServiceConnection::query()
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<string, mixed> $connectionProps
     */
    protected function selectedConnection(array $connectionProps): ?ServiceConnection
    {
        $connectionId = $connectionProps['selected_connection_id'];

        if (! is_int($connectionId)) {
            return null;
        }

        return ServiceConnection::query()->findOrFail($connectionId);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function commonRules(): array
    {
        return [
            'connection' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
