<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\QueueBulkAction;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\BulkQueueItemsRequest;
use App\Http\Requests\Library\MarkHistoryFailedRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Actions\BulkItemOutcome;
use App\Services\Actions\BulkRunner;
use App\Services\Arr\ArrClient;
use App\Services\Arr\ManualImportResolver;
use App\Services\Arr\QueueItemRemover;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use App\Support\UpstreamErrorText;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ActivityController extends Controller
{
    /**
     * Sonarr + Radarr activity. The live queue (both services, merged) and
     * one service's history page are deferred separately so the shell
     * renders first; history is per service because two independently
     * paged feeds cannot be merged into one correct page.
     */
    public function queue(Request $request): Response
    {
        $historyService = $request->query('history_service') === 'radarr' ? 'radarr' : 'sonarr';
        $historyPage = min(10_000, max(1, $request->integer('history_page', 1)));

        return Inertia::render('Library/Activity', [
            'queue' => Inertia::defer(fn (): array => $this->loadCombinedQueue()),
            'historyFilters' => [
                'service' => $historyService,
                'page' => $historyPage,
                'active' => $request->has('history_service') || $request->has('history_page'),
            ],
            'history' => Inertia::defer(fn (): array => $this->loadHistory($historyService, $historyPage), 'history'),
        ]);
    }

    /**
     * Skip the RSS-sync delay on a queued release and grab it now.
     * Common for stuck "delay" status rows where the user knows the
     * release is good and doesn't want to wait an hour for the next
     * indexer poll.
     */
    public function grabQueueItem(string $service, int $id): RedirectResponse
    {
        $client = $this->resolveClient($service);
        if (! $client instanceof ArrClient) {
            return $this->flashAndBack('error', __('Unknown service.'));
        }

        try {
            $client->grabQueueItem($id);
        } catch (RequestException|ConnectionException $throwable) {
            return $this->flashAndBack('error', __('Force grab failed: :msg', ['msg' => $throwable->getMessage()]));
        }

        return $this->flashAndBack('success', __('Grab triggered.'));
    }

    /**
     * Drop a stuck or unwanted item from the *arr download queue. Verb
     * controls intent: `remove` strips it from the queue without further
     * action; `block` additionally blocklists the release and triggers a
     * re-search so the next better match downloads instead.
     */
    public function removeQueueItem(Request $request, string $service, int $id, QueueItemRemover $queueItemRemover): RedirectResponse
    {
        $verb = (string) $request->input('verb', 'remove');

        if (! in_array($verb, ['remove', 'block'], true)) {
            return $this->flashAndBack('error', __('Invalid removal verb.'));
        }

        $connection = $this->resolveConnection($service);
        if (! $connection instanceof ServiceConnection) {
            return $this->flashAndBack('error', __('Unknown service.'));
        }

        try {
            $queueItemRemover->remove($connection, $id, $verb === 'block');
        } catch (RequestException|ConnectionException $throwable) {
            return $this->flashAndBack('error', __('Queue removal failed: :msg', ['msg' => UpstreamErrorText::sanitize($throwable->getMessage())]));
        }

        return $this->flashAndBack(
            'success',
            $verb === 'block'
                ? __('Removed and blocklisted; a fresh search will run.')
                : __('Removed from queue.'),
        );
    }

    /**
     * Remove or blocklist many queue items of one service, resolved to the
     * same active connection the single-item remove path uses (not a
     * client-supplied pin). No active connection for the service refuses
     * the whole request before anything is sent.
     */
    public function bulkQueue(BulkQueueItemsRequest $bulkQueueItemsRequest, QueueItemRemover $queueItemRemover, BulkRunner $bulkRunner): JsonResponse
    {
        $validated = $bulkQueueItemsRequest->validated();
        $service = (string) $validated['service'];
        $queueBulkAction = QueueBulkAction::from((string) $validated['action']);

        $connection = $this->resolveConnection($service);
        if (! $connection instanceof ServiceConnection) {
            return response()->json(['message' => __('No :service connection configured.', ['service' => ucfirst($service)])], 422);
        }

        $label = $connection->type->label();

        // A connect-level outage costs one retried round-trip per item. Once
        // the service has dropped one connection, the rest of the batch is
        // failed locally instead of repeating that cost for every remaining
        // id. A 4xx (RequestException) never trips this — only a transport
        // failure means the rest will fail too.
        $unreachable = false;

        $bulkSummary = $bulkRunner->run(
            $bulkQueueItemsRequest->bulkIds(),
            function (int $queueId) use ($queueItemRemover, $connection, $queueBulkAction, $label, &$unreachable): BulkItemOutcome {
                if ($unreachable) {
                    return BulkItemOutcome::failed(__(':service is unreachable right now.', ['service' => $label]));
                }

                try {
                    $queueItemRemover->remove($connection, $queueId, $queueBulkAction === QueueBulkAction::Blocklist);
                } catch (ConnectionException $connectionException) {
                    $unreachable = true;

                    return BulkItemOutcome::fromUpstreamFailure($connectionException, $label);
                } catch (RequestException $requestException) {
                    return BulkItemOutcome::fromUpstreamFailure($requestException, $label);
                }

                return BulkItemOutcome::started();
            },
            $this->queueTitles($connection),
        );

        return response()->json($bulkSummary->withToast($queueBulkAction->pastTense()));
    }

    /**
     * Failure-line names from the service's own queue, fetched once and
     * only when an item failed.
     *
     * @return Closure(int): string
     */
    private function queueTitles(ServiceConnection $serviceConnection): Closure
    {
        /** @var array<int, string>|null $titles */
        $titles = null;

        return function (int $queueId) use (&$titles, $serviceConnection): string {
            if ($titles === null) {
                $errors = [];
                $rows = $serviceConnection->type === ServiceType::Sonarr
                    ? $this->fetchSonarr($serviceConnection, $errors)
                    : $this->fetchRadarr($serviceConnection, $errors);
                $titles = [];

                foreach ($rows as $row) {
                    if (is_int($row['id'] ?? null) && is_string($row['title'] ?? null)) {
                        $titles[$row['id']] = $row['title'];
                    }
                }
            }

            return $titles[$queueId] ?? sprintf('#%d', $queueId);
        };
    }

    /**
     * Mark a grabbed history row failed: Sonarr/Radarr blocklist the release
     * and, with "Redownload failed" on, search again. History ids overlap
     * between instances, so the call is pinned to the connection the tab
     * was rendered from and refuses anything else.
     */
    public function markHistoryFailed(MarkHistoryFailedRequest $markHistoryFailedRequest, string $service, int $id): RedirectResponse
    {
        $validated = $markHistoryFailedRequest->validated();
        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $label = $serviceType->label();

        try {
            $connection = ServiceConnection::resolvePinnedStrict(['service_connection_id' => (int) $validated['service_connection_id']], $serviceType);
        } catch (InvalidArgumentException|ModelNotFoundException) {
            return $this->flashAndBack('error', __('That :service connection is unavailable — refresh and try again.', ['service' => $label]));
        }

        $client = $serviceType === ServiceType::Sonarr ? new SonarrClient($connection) : new RadarrClient($connection);

        try {
            $client->markHistoryFailed($id);
        } catch (ConnectionException) {
            return $this->flashAndBack('error', __(':service is unreachable right now.', ['service' => $label]));
        } catch (RequestException $requestException) {
            return $this->flashAndBack('error', match (true) {
                $requestException->response->serverError() => __(':service is unreachable right now.', ['service' => $label]),
                $requestException->response->status() === 404 => __('That history entry no longer exists in :service.', ['service' => $label]),
                default => __(':service refused to mark it as failed.', ['service' => $label]),
            });
        }

        ActivityLog::create([
            'user_id' => $markHistoryFailedRequest->user()->id,
            'service_connection_id' => $connection->id,
            'action' => 'library.history.marked_failed',
            'description' => sprintf('Marked %s history item %d as failed.', $label, $id),
            'metadata' => ['history_id' => $id],
        ]);

        return $this->flashAndBack('success', __("Marked as failed — :service will blocklist the release and search again if 'Redownload failed' is on.", ['service' => $label]));
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

        $files = resolve(ManualImportResolver::class)->toImportPayload($candidates, $service, $downloadId);
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
        $connection = $this->resolveConnection($service);

        return $connection instanceof ServiceConnection ? $this->clientFor($service, $connection) : null;
    }

    private function resolveConnection(string $service): ?ServiceConnection
    {
        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => null,
        };

        return $type === null ? null : $this->safeResolve($type);
    }

    private function clientFor(string $service, ServiceConnection $serviceConnection): ArrClient
    {
        return $service === 'sonarr'
            ? new SonarrClient($serviceConnection)
            : new RadarrClient($serviceConnection);
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

    /** Page size per service for the history table. */
    private const int HISTORY_PAGE_SIZE = 50;

    /**
     * @return array{service: string, configured: bool, connection_id: int|null, rows: list<array<string, mixed>>, page: int, page_size: int, total: int, error: string|null}
     */
    private function loadHistory(string $service, int $page): array
    {
        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $connection = $this->safeResolve($serviceType);

        $result = [
            'service' => $service,
            'configured' => $connection instanceof ServiceConnection,
            'connection_id' => $connection?->id,
            'rows' => [],
            'page' => $page,
            'page_size' => self::HISTORY_PAGE_SIZE,
            'total' => 0,
            'error' => null,
        ];

        if (! $connection instanceof ServiceConnection) {
            return $result;
        }

        $params = [
            'page' => $page,
            'pageSize' => self::HISTORY_PAGE_SIZE,
            'sortKey' => 'date',
            'sortDirection' => 'descending',
        ];

        try {
            $payload = $serviceType === ServiceType::Sonarr
                ? new SonarrClient($connection)->getHistory([...$params, 'includeSeries' => 'true', 'includeEpisode' => 'true'])
                : new RadarrClient($connection)->getHistory([...$params, 'includeMovie' => 'true']);
        } catch (RequestException|ConnectionException) {
            return [...$result, 'error' => sprintf('%s is unreachable right now — its history could not be loaded.', $serviceType->label())];
        }

        $records = is_array($payload['records'] ?? null) ? array_values(array_filter($payload['records'], is_array(...))) : [];

        return [
            ...$result,
            'rows' => array_map(
                fn (array $record): array => $serviceType === ServiceType::Sonarr
                    ? $this->mapSonarrHistory($record, $connection)
                    : $this->mapRadarrHistory($record, $connection),
                $records,
            ),
            'total' => (int) ($payload['totalRecords'] ?? count($records)),
        ];
    }

    /**
     * Never pass the record's `data` map on: grabbed rows carry the indexer
     * downloadUrl (with its API key) and the release guid.
     *
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
            'service_url' => $serviceConnection->linkUrl(),
            'event_type' => $record['eventType'] ?? null,
            'title' => $title,
            'subtitle' => $subtitle,
            'source_title' => $record['sourceTitle'] ?? null,
            'quality' => $record['quality']['quality']['name'] ?? null,
            'download_client' => $record['downloadClient'] ?? null,
            'date' => $record['date'] ?? null,
        ];
    }

    /**
     * Never pass the record's `data` map on: grabbed rows carry the indexer
     * downloadUrl (with its API key) and the release guid.
     *
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
            'service_url' => $serviceConnection->linkUrl(),
            'event_type' => $record['eventType'] ?? null,
            'title' => $title,
            'subtitle' => $year === null ? null : (string) $year,
            'source_title' => $record['sourceTitle'] ?? null,
            'quality' => $record['quality']['quality']['name'] ?? null,
            'download_client' => $record['downloadClient'] ?? null,
            'date' => $record['date'] ?? null,
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
            'service_url' => $serviceConnection->linkUrl(),
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
            'service_url' => $serviceConnection->linkUrl(),
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
