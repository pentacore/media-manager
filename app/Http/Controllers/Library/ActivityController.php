<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    /**
     * Combined Sonarr + Radarr activity view. Both the live download queue
     * and the recent grab/import/failure history are deferred so the
     * shell renders before the *arr round-trips finish; the Vue side
     * pivots between them with a tab toggle.
     */
    public function queue(): Response
    {
        return Inertia::render('Library/Activity', [
            'queue' => Inertia::defer(fn (): array => $this->loadCombinedQueue()),
            'history' => Inertia::defer(fn (): array => $this->loadCombinedHistory()),
        ]);
    }

    /**
     * Drop a stuck or unwanted item from the *arr download queue. Verb
     * controls intent: `remove` strips it from the queue without further
     * action; `block` additionally blocklists the release and triggers a
     * re-search so the next better match downloads instead.
     */
    public function removeQueueItem(Request $request, string $service, int $id): RedirectResponse
    {
        $verb = (string) $request->input('verb', 'remove');

        if (! in_array($verb, ['remove', 'block'], true)) {
            return $this->flashAndBack('error', __('Invalid removal verb.'));
        }

        $client = $this->resolveClient($service);
        if (! $client instanceof ArrClient) {
            return $this->flashAndBack('error', __('Unknown service.'));
        }

        try {
            $client->removeQueueItem(
                id: $id,
                removeFromClient: true,
                blocklist: $verb === 'block',
                skipRedownload: $verb === 'remove',
            );
        } catch (RequestException|ConnectionException $throwable) {
            return $this->flashAndBack('error', __('Queue removal failed: :msg', ['msg' => $throwable->getMessage()]));
        }

        return $this->flashAndBack(
            'success',
            $verb === 'block'
                ? __('Removed and blocklisted; a fresh search will run.')
                : __('Removed from queue.'),
        );
    }

    /**
     * Look up the candidate files Sonarr/Radarr will offer up if we ask
     * it to manually import this stuck download. Returned shape is the
     * upstream ManualImportResource trimmed to what the modal needs.
     */
    public function manualImportCandidates(string $service, string $downloadId): JsonResponse
    {
        $client = $this->resolveClient($service);
        if (! $client instanceof ArrClient) {
            return new JsonResponse(['error' => 'Unknown service.'], 422);
        }

        try {
            $candidates = $client->getManualImport(['downloadId' => $downloadId]);
        } catch (RequestException|ConnectionException $throwable) {
            return new JsonResponse(['error' => $throwable->getMessage()], 502);
        }

        return new JsonResponse([
            'candidates' => array_values(array_map(
                fn (array $candidate): array => $this->mapCandidate($candidate, $service),
                $candidates,
            )),
        ]);
    }

    /**
     * Trigger the ManualImport command. We re-fetch candidates server-side
     * so the caller cannot inject paths or rewrite the foreign keys —
     * frontend only supplies the downloadId we already showed it.
     */
    public function executeManualImport(Request $request, string $service): RedirectResponse
    {
        $downloadId = (string) $request->input('download_id');
        if ($downloadId === '') {
            return $this->flashAndBack('error', __('Missing downloadId.'));
        }

        $client = $this->resolveClient($service);
        if (! $client instanceof ArrClient) {
            return $this->flashAndBack('error', __('Unknown service.'));
        }

        try {
            $candidates = $client->getManualImport(['downloadId' => $downloadId]);
        } catch (RequestException|ConnectionException $throwable) {
            return $this->flashAndBack('error', __('Could not enumerate import candidates: :msg', ['msg' => $throwable->getMessage()]));
        }

        $files = $this->candidatesToImportPayload($candidates, $service, $downloadId);
        if ($files === []) {
            return $this->flashAndBack('error', __('Sonarr/Radarr returned no importable files for this download.'));
        }

        try {
            $client->runCommand('ManualImport', [
                'files' => $files,
                'importMode' => 'auto',
            ]);
        } catch (RequestException|ConnectionException $throwable) {
            return $this->flashAndBack('error', __('Manual import failed: :msg', ['msg' => $throwable->getMessage()]));
        }

        return $this->flashAndBack('success', __('Manual import queued (:n file(s)).', ['n' => count($files)]));
    }

    /**
     * Convert raw candidates into the file shape Sonarr/Radarr expect on
     * the ManualImport command. Drops candidates that lack the required
     * foreign key (Sonarr → seriesId+episodeIds, Radarr → movieId).
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function candidatesToImportPayload(array $candidates, string $service, string $downloadId): array
    {
        $files = [];

        foreach ($candidates as $candidate) {
            $base = [
                'path' => $candidate['path'] ?? null,
                'folderName' => $candidate['folderName'] ?? null,
                'quality' => $candidate['quality'] ?? null,
                'languages' => $candidate['languages'] ?? [],
                'releaseGroup' => $candidate['releaseGroup'] ?? null,
                'indexerFlags' => $candidate['indexerFlags'] ?? 0,
                'downloadId' => $downloadId,
            ];
            if ($base['path'] === null) {
                continue;
            }

            if ($base['quality'] === null) {
                continue;
            }

            if ($service === 'sonarr') {
                $seriesId = $candidate['series']['id'] ?? null;
                $episodeIds = array_values(array_filter(array_map(
                    static fn (array $episode): ?int => isset($episode['id']) ? (int) $episode['id'] : null,
                    is_array($candidate['episodes'] ?? null) ? $candidate['episodes'] : [],
                )));
                if ($seriesId === null) {
                    continue;
                }

                if ($episodeIds === []) {
                    continue;
                }

                $files[] = [
                    ...$base,
                    'seriesId' => $seriesId,
                    'episodeIds' => $episodeIds,
                    'releaseType' => $candidate['releaseType'] ?? null,
                ];

                continue;
            }

            $movieId = $candidate['movie']['id'] ?? null;
            if ($movieId === null) {
                continue;
            }

            $files[] = [
                ...$base,
                'movieId' => $movieId,
            ];
        }

        return $files;
    }

    /**
     * Trim a candidate down to what the modal renders so we don't ship
     * the entire upstream payload (which can be huge per file).
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function mapCandidate(array $candidate, string $service): array
    {
        $shared = [
            'path' => $candidate['path'] ?? null,
            'name' => $candidate['name'] ?? ($candidate['relativePath'] ?? null),
            'size' => $candidate['size'] ?? null,
            'quality' => $candidate['quality']['quality']['name'] ?? null,
            'release_group' => $candidate['releaseGroup'] ?? null,
            'languages' => array_values(array_map(
                static fn (array $language): ?string => $language['name'] ?? null,
                is_array($candidate['languages'] ?? null) ? $candidate['languages'] : [],
            )),
            'rejections' => array_values(array_map(
                static fn (array $rejection): array => [
                    'reason' => $rejection['reason'] ?? '',
                    'type' => $rejection['type'] ?? null,
                ],
                is_array($candidate['rejections'] ?? null) ? $candidate['rejections'] : [],
            )),
        ];

        if ($service === 'sonarr') {
            return [
                ...$shared,
                'series_title' => $candidate['series']['title'] ?? null,
                'season' => $candidate['seasonNumber'] ?? null,
                'episodes' => array_values(array_map(
                    static fn (array $episode): array => [
                        'season' => $episode['seasonNumber'] ?? null,
                        'episode' => $episode['episodeNumber'] ?? null,
                        'title' => $episode['title'] ?? null,
                    ],
                    is_array($candidate['episodes'] ?? null) ? $candidate['episodes'] : [],
                )),
            ];
        }

        return [
            ...$shared,
            'movie_title' => $candidate['movie']['title'] ?? null,
            'movie_year' => $candidate['movie']['year'] ?? null,
        ];
    }

    private function resolveClient(string $service): ?ArrClient
    {
        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $connection = $this->safeResolve($type);
        if (! $connection instanceof ServiceConnection) {
            return null;
        }

        return $service === 'sonarr'
            ? new SonarrClient($connection)
            : new RadarrClient($connection);
    }

    private function flashAndBack(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
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

    /** Page size per service for the merged history table. */
    private const int HISTORY_PAGE_SIZE = 50;

    /**
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>, services: array<string, bool>}
     */
    private function loadCombinedHistory(): array
    {
        $rows = [];
        $errors = [];
        $services = [];

        $sonarr = $this->safeResolve(ServiceType::Sonarr);
        $services['sonarr'] = $sonarr instanceof ServiceConnection;
        if ($sonarr instanceof ServiceConnection) {
            $rows = [...$rows, ...$this->fetchSonarrHistory($sonarr, $errors)];
        }

        $radarr = $this->safeResolve(ServiceType::Radarr);
        $services['radarr'] = $radarr instanceof ServiceConnection;
        if ($radarr instanceof ServiceConnection) {
            $rows = [...$rows, ...$this->fetchRadarrHistory($radarr, $errors)];
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        return ['rows' => $rows, 'errors' => $errors, 'services' => $services];
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<int, array<string, mixed>>
     */
    private function fetchSonarrHistory(ServiceConnection $serviceConnection, array &$errors): array
    {
        try {
            $payload = new SonarrClient($serviceConnection)->getHistory([
                'page' => 1,
                'pageSize' => self::HISTORY_PAGE_SIZE,
                'sortKey' => 'date',
                'sortDirection' => 'descending',
                'includeSeries' => 'true',
                'includeEpisode' => 'true',
            ]);
        } catch (RequestException|ConnectionException $throwable) {
            $errors[] = 'Sonarr: '.$throwable->getMessage();

            return [];
        }

        $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];

        return array_map(
            fn (array $record): array => $this->mapSonarrHistory($record, $serviceConnection),
            $records,
        );
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<int, array<string, mixed>>
     */
    private function fetchRadarrHistory(ServiceConnection $serviceConnection, array &$errors): array
    {
        try {
            $payload = new RadarrClient($serviceConnection)->getHistory([
                'page' => 1,
                'pageSize' => self::HISTORY_PAGE_SIZE,
                'sortKey' => 'date',
                'sortDirection' => 'descending',
                'includeMovie' => 'true',
            ]);
        } catch (RequestException|ConnectionException $throwable) {
            $errors[] = 'Radarr: '.$throwable->getMessage();

            return [];
        }

        $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];

        return array_map(
            fn (array $record): array => $this->mapRadarrHistory($record, $serviceConnection),
            $records,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function mapSonarrHistory(array $record, ServiceConnection $serviceConnection): array
    {
        $series = is_array($record['series'] ?? null) ? $record['series'] : [];
        $episode = is_array($record['episode'] ?? null) ? $record['episode'] : [];

        $title = ($series['title'] ?? null) ?: ($record['sourceTitle'] ?? null);
        $subtitle = $episode === []
            ? null
            : sprintf('S%02dE%02d · %s', (int) ($episode['seasonNumber'] ?? 0), (int) ($episode['episodeNumber'] ?? 0), $episode['title'] ?? '');

        return [
            'id' => $record['id'] ?? null,
            'service' => 'sonarr',
            'service_url' => rtrim($serviceConnection->url, '/'),
            'event_type' => $record['eventType'] ?? null,
            'title' => $title,
            'subtitle' => $subtitle,
            'source_title' => $record['sourceTitle'] ?? null,
            'quality' => $record['quality']['quality']['name'] ?? null,
            'download_client' => $record['downloadClient'] ?? null,
            'date' => $record['date'] ?? null,
            'data' => $record['data'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function mapRadarrHistory(array $record, ServiceConnection $serviceConnection): array
    {
        $movie = is_array($record['movie'] ?? null) ? $record['movie'] : [];
        $title = ($movie['title'] ?? null) ?: ($record['sourceTitle'] ?? null);
        $year = $movie['year'] ?? null;

        return [
            'id' => $record['id'] ?? null,
            'service' => 'radarr',
            'service_url' => rtrim($serviceConnection->url, '/'),
            'event_type' => $record['eventType'] ?? null,
            'title' => $title,
            'subtitle' => $year === null ? null : (string) $year,
            'source_title' => $record['sourceTitle'] ?? null,
            'quality' => $record['quality']['quality']['name'] ?? null,
            'download_client' => $record['downloadClient'] ?? null,
            'date' => $record['date'] ?? null,
            'data' => $record['data'] ?? null,
        ];
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
            'download_id' => $record['downloadId'] ?? null,
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
            'download_id' => $record['downloadId'] ?? null,
        ];
    }
}
