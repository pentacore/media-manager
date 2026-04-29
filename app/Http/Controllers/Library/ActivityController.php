<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    /**
     * Combined Sonarr + Radarr download queue. Both *arr APIs paginate
     * server-side, so we ask each one for the same page slice and stitch
     * the rows together client-side, tagged by service. Future work will
     * add filtering and history/blocklist tabs (TODO L52-55).
     */
    public function queue(): Response
    {
        return Inertia::render('Library/Activity', [
            'queue' => Inertia::defer(fn (): array => $this->loadCombinedQueue()),
        ]);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>, services: array<string, bool>}
     */
    private function loadCombinedQueue(): array
    {
        $rows = [];
        $errors = [];
        $services = [];

        $sonarr = $this->safeResolve(ServiceType::Sonarr);
        $services['sonarr'] = $sonarr instanceof ServiceConnection;
        if ($sonarr instanceof ServiceConnection) {
            $rows = [...$rows, ...$this->fetchSonarr($sonarr, $errors)];
        }

        $radarr = $this->safeResolve(ServiceType::Radarr);
        $services['radarr'] = $radarr instanceof ServiceConnection;
        if ($radarr instanceof ServiceConnection) {
            $rows = [...$rows, ...$this->fetchRadarr($radarr, $errors)];
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) ($b['added'] ?? ''), (string) ($a['added'] ?? '')));

        return ['rows' => $rows, 'errors' => $errors, 'services' => $services];
    }

    private function safeResolve(ServiceType $serviceType): ?ServiceConnection
    {
        try {
            return ServiceConnection::resolveActive($serviceType);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<int, array<string, mixed>>
     */
    private function fetchSonarr(ServiceConnection $serviceConnection, array &$errors): array
    {
        try {
            $payload = new SonarrClient($serviceConnection)->getQueue([
                'page' => 1,
                'pageSize' => 100,
                'sortKey' => 'timeleft',
                'sortDirection' => 'ascending',
                'includeUnknownSeriesItems' => 'true',
                'includeSeries' => 'true',
                'includeEpisode' => 'true',
            ]);
        } catch (RequestException|ConnectionException $throwable) {
            $errors[] = 'Sonarr: '.$throwable->getMessage();

            return [];
        }

        $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];

        return array_map(
            fn (array $record): array => $this->mapSonarr($record, $serviceConnection),
            $records,
        );
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<int, array<string, mixed>>
     */
    private function fetchRadarr(ServiceConnection $serviceConnection, array &$errors): array
    {
        try {
            $payload = new RadarrClient($serviceConnection)->getQueue([
                'page' => 1,
                'pageSize' => 100,
                'sortKey' => 'timeleft',
                'sortDirection' => 'ascending',
                'includeUnknownMovieItems' => 'true',
                'includeMovie' => 'true',
            ]);
        } catch (RequestException|ConnectionException $throwable) {
            $errors[] = 'Radarr: '.$throwable->getMessage();

            return [];
        }

        $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];

        return array_map(
            fn (array $record): array => $this->mapRadarr($record, $serviceConnection),
            $records,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function mapSonarr(array $record, ServiceConnection $serviceConnection): array
    {
        $series = is_array($record['series'] ?? null) ? $record['series'] : [];
        $episode = is_array($record['episode'] ?? null) ? $record['episode'] : [];

        $title = ($series['title'] ?? null) ?: ($record['title'] ?? null);
        $subtitle = $episode === []
            ? null
            : sprintf('S%02dE%02d · %s', (int) ($episode['seasonNumber'] ?? 0), (int) ($episode['episodeNumber'] ?? 0), $episode['title'] ?? '');

        return [
            'id' => $record['id'] ?? null,
            'service' => 'sonarr',
            'service_url' => rtrim($serviceConnection->url, '/'),
            'title' => $title,
            'subtitle' => $subtitle,
            'status' => $record['status'] ?? null,
            'tracked_status' => $record['trackedDownloadStatus'] ?? null,
            'tracked_state' => $record['trackedDownloadState'] ?? null,
            'protocol' => $record['protocol'] ?? null,
            'download_client' => $record['downloadClient'] ?? null,
            'size' => $record['size'] ?? null,
            'sizeleft' => $record['sizeleft'] ?? null,
            'timeleft' => $record['timeleft'] ?? null,
            'estimated_completion_time' => $record['estimatedCompletionTime'] ?? null,
            'error_message' => $record['errorMessage'] ?? null,
            'status_messages' => $record['statusMessages'] ?? [],
            'added' => $record['added'] ?? null,
            'quality' => $record['quality']['quality']['name'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function mapRadarr(array $record, ServiceConnection $serviceConnection): array
    {
        $movie = is_array($record['movie'] ?? null) ? $record['movie'] : [];
        $title = ($movie['title'] ?? null) ?: ($record['title'] ?? null);
        $year = $movie['year'] ?? null;

        return [
            'id' => $record['id'] ?? null,
            'service' => 'radarr',
            'service_url' => rtrim($serviceConnection->url, '/'),
            'title' => $title,
            'subtitle' => $year === null ? null : (string) $year,
            'status' => $record['status'] ?? null,
            'tracked_status' => $record['trackedDownloadStatus'] ?? null,
            'tracked_state' => $record['trackedDownloadState'] ?? null,
            'protocol' => $record['protocol'] ?? null,
            'download_client' => $record['downloadClient'] ?? null,
            'size' => $record['size'] ?? null,
            'sizeleft' => $record['sizeleft'] ?? null,
            'timeleft' => $record['timeleft'] ?? null,
            'estimated_completion_time' => $record['estimatedCompletionTime'] ?? null,
            'error_message' => $record['errorMessage'] ?? null,
            'status_messages' => $record['statusMessages'] ?? [],
            'added' => $record['added'] ?? null,
            'quality' => $record['quality']['quality']['name'] ?? null,
        ];
    }
}
