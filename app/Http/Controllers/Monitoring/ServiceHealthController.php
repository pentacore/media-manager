<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Enums\HealthStatus;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ServiceHealthController extends Controller
{
    public function __invoke(): Response
    {
        $connections = ServiceConnection::orderBy('type')->orderBy('name')->get();

        return Inertia::render('Monitoring/ServiceHealth', [
            'connections' => $connections->map(fn (ServiceConnection $serviceConnection): array => [
                'id' => $serviceConnection->id,
                'name' => $serviceConnection->name,
                'type' => $serviceConnection->type->value,
                'url' => $serviceConnection->url,
                'is_active' => $serviceConnection->is_active,
                'health_status' => ($serviceConnection->health_status ?? HealthStatus::Unknown)->value,
                'version' => $serviceConnection->version,
                'latest_version' => $serviceConnection->latest_version,
                'update_available' => $serviceConnection->latest_version !== null
                    && $serviceConnection->version !== null
                    && $serviceConnection->latest_version !== $serviceConnection->version,
                'last_seen_at' => $serviceConnection->last_seen_at?->toISOString(),
                'disk_space' => $this->diskSpaceFor($serviceConnection),
            ])->all(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function diskSpaceFor(ServiceConnection $serviceConnection): ?array
    {
        if (! $serviceConnection->is_active) {
            return null;
        }

        $client = match ($serviceConnection->type) {
            ServiceType::Sonarr => new SonarrClient($serviceConnection),
            ServiceType::Radarr => new RadarrClient($serviceConnection),
            default => null,
        };

        if ($client === null) {
            return null;
        }

        try {
            $entries = $client->getDiskSpace();
        } catch (RequestException|ConnectionException|Throwable) {
            return null;
        }

        return array_map(fn (array $entry): array => [
            'path' => $entry['path'] ?? null,
            'label' => $entry['label'] ?? null,
            'free_space' => $entry['freeSpace'] ?? null,
            'total_space' => $entry['totalSpace'] ?? null,
        ], $entries);
    }
}
