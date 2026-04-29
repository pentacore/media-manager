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

        $disks = array_map(static fn (array $entry): array => [
            'path' => $entry['path'] ?? null,
            'label' => $entry['label'] ?? null,
            'free_space' => $entry['freeSpace'] ?? null,
            'total_space' => $entry['totalSpace'] ?? null,
        ], $entries);

        return $this->applyDiskPreferences($serviceConnection, $disks);
    }

    /**
     * Filter / aggregate disks per the connection's settings.disk picker
     * and stamp each row with its preferred display metric.
     *
     * - mode=all (default): return every disk untouched (display=both).
     * - mode=selected: keep only disks whose path matches a chosen entry.
     * - mode=sum: collapse the chosen subset (or every disk if none were
     *   chosen) into a single synthetic row keyed by 'sum'.
     *
     * settings.disk.display is a path → metric map; the magic key 'sum'
     * controls the aggregated row when mode=sum. Allowed metric values:
     * 'free' | 'used' | 'both'. Missing / invalid values fall back to
     * 'both' so existing connections keep their current rendering.
     *
     * @param  array<int, array<string, mixed>>  $disks
     * @return array<int, array<string, mixed>>
     */
    private function applyDiskPreferences(ServiceConnection $serviceConnection, array $disks): array
    {
        $settings = is_array($serviceConnection->settings['disk'] ?? null)
            ? $serviceConnection->settings['disk']
            : null;

        $displayMap = is_array($settings['display'] ?? null) ? $settings['display'] : [];
        $resolveDisplay = static function (string $key) use ($displayMap): string {
            $value = $displayMap[$key] ?? 'both';

            return in_array($value, ['free', 'used', 'both'], true) ? $value : 'both';
        };

        if ($settings === null || ($settings['mode'] ?? 'all') === 'all') {
            return array_map(
                static fn (array $disk): array => $disk + ['display' => $resolveDisplay((string) ($disk['path'] ?? ''))],
                $disks,
            );
        }

        $mode = $settings['mode'];
        $paths = is_array($settings['paths'] ?? null)
            ? array_values(array_filter($settings['paths'], is_string(...)))
            : [];

        if ($paths === [] && $mode === 'selected') {
            return array_map(
                static fn (array $disk): array => $disk + ['display' => $resolveDisplay((string) ($disk['path'] ?? ''))],
                $disks,
            );
        }

        $matching = $paths === []
            ? $disks
            : array_values(array_filter(
                $disks,
                static fn (array $disk): bool => in_array($disk['path'] ?? null, $paths, true),
            ));

        if ($mode !== 'sum') {
            return array_map(
                static fn (array $disk): array => $disk + ['display' => $resolveDisplay((string) ($disk['path'] ?? ''))],
                $matching,
            );
        }

        if ($matching === []) {
            return [];
        }

        $free = 0;
        $total = 0;

        foreach ($matching as $disk) {
            $free += (int) ($disk['free_space'] ?? 0);
            $total += (int) ($disk['total_space'] ?? 0);
        }

        return [[
            'path' => 'sum',
            'label' => sprintf('Sum of %d path%s', count($matching), count($matching) === 1 ? '' : 's'),
            'free_space' => $free,
            'total_space' => $total,
            'display' => $resolveDisplay('sum'),
        ]];
    }
}
