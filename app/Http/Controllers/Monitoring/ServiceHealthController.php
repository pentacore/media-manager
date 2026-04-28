<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceConnectionResource;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\ServiceClientFactory;
use App\Services\ServiceMetrics\ServiceMetricsRepository;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ServiceHealthController extends Controller
{
    public function __invoke(Request $request, ServiceMetricsRepository $serviceMetricsRepository): Response
    {
        $connections = ServiceConnection::orderBy('type')->orderBy('name')->get();
        $ids = $connections->pluck('id')->all();

        $strips = $serviceMetricsRepository->last60MinutesFor($ids);
        $uptime = [];
        $avgLatency = [];

        foreach ($ids as $id) {
            $uptime[$id] = $serviceMetricsRepository->uptimePercent($id);
            $avgLatency[$id] = $serviceMetricsRepository->averageLatencyMs($id);
        }

        return Inertia::render('Monitoring/ServiceHealth', [
            'connections' => ServiceConnectionResource::collection($connections)->toArray($request),
            'metrics' => [
                'strips' => $strips,
                'uptime' => $uptime,
                'avg_latency' => $avgLatency,
            ],
            'diskSpace' => Inertia::defer(fn (): array => $this->loadDiskSpaceForAll($connections)),
            'prowlarrIndexers' => Inertia::defer(fn (): array => $this->loadProwlarrIndexersForAll($connections)),
        ]);
    }

    /**
     * @param  Collection<int, ServiceConnection>  $connections
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function loadDiskSpaceForAll(Collection $connections): array
    {
        $result = [];

        foreach ($connections as $connection) {
            $result[$connection->id] = $this->diskSpaceFor($connection) ?? [];
        }

        return $result;
    }

    /**
     * @param  Collection<int, ServiceConnection>  $connections
     * @return array<int, array<int, array{id: int|null, name: string|null, enable: bool}>>
     */
    private function loadProwlarrIndexersForAll(Collection $connections): array
    {
        $result = [];

        foreach ($connections as $connection) {
            if ($connection->type !== ServiceType::Prowlarr) {
                continue;
            }

            if (! $connection->is_active) {
                continue;
            }

            try {
                $entries = new ProwlarrClient($connection)->listIndexers();
                $result[$connection->id] = array_map(fn (array $entry): array => [
                    'id' => $entry['id'] ?? null,
                    'name' => $entry['name'] ?? null,
                    'enable' => $entry['enable'] ?? false,
                ], $entries);
            } catch (Throwable) {
                $result[$connection->id] = [];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function diskSpaceFor(ServiceConnection $serviceConnection): ?array
    {
        if (! $serviceConnection->is_active) {
            return null;
        }

        if (! in_array($serviceConnection->type, [ServiceType::Sonarr, ServiceType::Radarr], true)) {
            return null;
        }

        $client = resolve(ServiceClientFactory::class)->make($serviceConnection);

        if (! $client instanceof SonarrClient && ! $client instanceof RadarrClient) {
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
