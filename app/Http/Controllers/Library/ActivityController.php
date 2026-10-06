<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\QueueBulkAction;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\BulkQueueItemsRequest;
use App\Http\Requests\Library\ExecuteManualImportRequest;
use App\Http\Requests\Library\GrabQueueItemRequest;
use App\Http\Requests\Library\ManualImportCandidatesRequest;
use App\Http\Requests\Library\MarkHistoryFailedRequest;
use App\Http\Requests\Library\RemoveQueueItemRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Actions\BulkItemOutcome;
use App\Services\Actions\BulkRunner;
use App\Services\Arr\ArrClient;
use App\Services\Arr\ArrConnections;
use App\Services\Arr\ArrWriteUnconfirmed;
use App\Services\Arr\GrabbedHistoryCache;
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
    public function __construct(private readonly ArrConnections $arrConnections) {}

    /**
     * Sonarr + Radarr activity. The live queue (both services, merged) and
     * one service's history page are deferred separately so the shell
     * renders first; history is per service because two independently
     * paged feeds cannot be merged into one correct page.
     */
    public function queue(Request $request, GrabbedHistoryCache $grabbedHistoryCache): Response
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
            'history' => Inertia::defer(fn (): array => $this->loadHistory($historyService, $historyPage, $grabbedHistoryCache), 'history'),
        ]);
    }

    /**
     * Skip the RSS-sync delay on a queued release and grab it now.
     * Common for stuck "delay" status rows where the user knows the
     * release is good and doesn't want to wait an hour for the next
     * indexer poll. Pinned to the connection the row was rendered from.
     */
    public function grabQueueItem(GrabQueueItemRequest $grabQueueItemRequest, string $service, int $id): RedirectResponse
    {
        $validated = $grabQueueItemRequest->validated();
        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $connection = $this->resolvePinnedQueueConnection($serviceType, (int) $validated['service_connection_id']);

        if (! $connection instanceof ServiceConnection) {
            return $this->flashAndBack('error', $this->unavailablePinMessage($serviceType));
        }

        try {
            $this->clientFor($connection)->grabQueueItem($id);
        } catch (ArrWriteUnconfirmed $arrWriteUnconfirmed) {
            // A 200 that is not JSON data (usually a proxy login page): the
            // grab may already have reached the arr, so this must read as
            // unknown, never as a refusal.
            return $this->flashAndBack('error', $arrWriteUnconfirmed->getMessage());
        } catch (RequestException|ConnectionException $throwable) {
            return $this->flashAndBack('error', __('Force grab failed: :msg', ['msg' => UpstreamErrorText::sanitize($throwable->getMessage())]));
        }

        return $this->flashAndBack('success', __('Grab triggered.'));
    }

    /**
     * Drop a stuck or unwanted item from the *arr download queue. Verb
     * controls intent: `remove` strips it from the queue without further
     * action; `block` additionally blocklists the release and triggers a
     * re-search so the next better match downloads instead. Pinned to the
     * connection the row was rendered from.
     */
    public function removeQueueItem(RemoveQueueItemRequest $removeQueueItemRequest, string $service, int $id, QueueItemRemover $queueItemRemover): RedirectResponse
    {
        $validated = $removeQueueItemRequest->validated();
        $verb = (string) ($validated['verb'] ?? 'remove');

        if (! in_array($verb, ['remove', 'block'], true)) {
            return $this->flashAndBack('error', __('Invalid removal verb.'));
        }

        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $connection = $this->resolvePinnedQueueConnection($serviceType, (int) $validated['service_connection_id']);

        if (! $connection instanceof ServiceConnection) {
            return $this->flashAndBack('error', $this->unavailablePinMessage($serviceType));
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
     * Remove or blocklist many queue items of one service. Pinned, like the
     * single-item path, to the connection the rows were rendered from: a
     * stale or mismatched pin refuses the whole request before anything is
     * sent.
     */
    public function bulkQueue(BulkQueueItemsRequest $bulkQueueItemsRequest, QueueItemRemover $queueItemRemover, BulkRunner $bulkRunner): JsonResponse
    {
        $validated = $bulkQueueItemsRequest->validated();
        $service = (string) $validated['service'];
        $queueBulkAction = QueueBulkAction::from((string) $validated['action']);

        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $connection = $this->resolvePinnedQueueConnection($serviceType, (int) $validated['service_connection_id']);

        if (! $connection instanceof ServiceConnection) {
            return response()->json(['message' => $this->unavailablePinMessage($serviceType)], 422);
        }

        $label = $connection->type->label();

        // A connect-level outage costs one retried round-trip per item. Once
        // the service has dropped one connection, or answered two server
        // errors in a row (a proxy's 502 for a stopped container), the rest
        // of the batch is failed locally instead of repeating that cost for
        // every remaining id. A 4xx never trips this, and neither does a
        // single 5xx, which can be specific to one item.
        $unreachable = false;
        $consecutiveServerErrors = 0;

        $bulkSummary = $bulkRunner->run(
            $bulkQueueItemsRequest->bulkIds(),
            function (int $queueId) use ($queueItemRemover, $connection, $queueBulkAction, $label, &$unreachable, &$consecutiveServerErrors): BulkItemOutcome {
                if ($unreachable) {
                    return BulkItemOutcome::failed(__(':service is unreachable right now.', ['service' => $label]));
                }

                try {
                    $queueItemRemover->remove($connection, $queueId, $queueBulkAction === QueueBulkAction::Blocklist);
                } catch (ConnectionException $connectionException) {
                    $unreachable = true;

                    return BulkItemOutcome::fromUpstreamFailure($connectionException, $label);
                } catch (RequestException $requestException) {
                    $consecutiveServerErrors = $requestException->response->serverError() ? $consecutiveServerErrors + 1 : 0;
                    $unreachable = $consecutiveServerErrors >= 2;

                    return BulkItemOutcome::fromUpstreamFailure($requestException, $label);
                }

                $consecutiveServerErrors = 0;

                return BulkItemOutcome::started();
            },
            $this->queueTitles($connection, $unreachable),
        );

        return response()->json($bulkSummary->withToast($queueBulkAction->pastTense()));
    }

    /**
     * Failure-line names from the service's own queue, fetched once and
     * only when an item failed. Once the batch has marked the service
     * unreachable, no queue read is sent: the queue id stands in.
     *
     * @return Closure(int): string
     */
    private function queueTitles(ServiceConnection $serviceConnection, bool &$unreachable): Closure
    {
        /** @var array<int, string>|null $titles */
        $titles = null;

        return function (int $queueId) use (&$titles, &$unreachable, $serviceConnection): string {
            if ($titles === null && $unreachable) {
                return sprintf('#%d', $queueId);
            }

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
     * was rendered from and refuses anything else, and only an id the
     * History tab rendered as a grab (see GrabbedHistoryCache) is accepted.
     */
    public function markHistoryFailed(MarkHistoryFailedRequest $markHistoryFailedRequest, string $service, int $id, GrabbedHistoryCache $grabbedHistoryCache): RedirectResponse
    {
        $validated = $markHistoryFailedRequest->validated();
        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $label = $serviceType->label();

        $connection = $this->resolvePinnedQueueConnection($serviceType, (int) $validated['service_connection_id']);

        if (! $connection instanceof ServiceConnection) {
            return $this->flashAndBack('error', $this->unavailablePinMessage($serviceType));
        }

        if (! $grabbedHistoryCache->isGrabbed($connection, $id)) {
            return $this->flashAndBack('error', __('Only a grabbed :service history entry can be marked as failed — refresh the history and try again.', ['service' => $label]));
        }

        $arrClient = $this->clientFor($connection);

        try {
            $arrClient->markHistoryFailed($id);
        } catch (ArrWriteUnconfirmed $arrWriteUnconfirmed) {
            // A 200 that is not JSON data (usually a proxy login page): the
            // write may already have reached the arr, so this must read as
            // unknown, never as a refusal.
            return $this->flashAndBack('error', $arrWriteUnconfirmed->getMessage());
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
     * Pinned to the connection the row was rendered from.
     */
    public function manualImportCandidates(ManualImportCandidatesRequest $manualImportCandidatesRequest, string $service, string $downloadId): JsonResponse
    {
        $validated = $manualImportCandidatesRequest->validated();
        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $connection = $this->resolvePinnedQueueConnection($serviceType, (int) $validated['service_connection_id']);

        if (! $connection instanceof ServiceConnection) {
            return new JsonResponse(['error' => $this->unavailablePinMessage($serviceType)], 422);
        }

        try {
            $candidates = $this->clientFor($connection)->getManualImport(['downloadId' => $downloadId]);
        } catch (RequestException|ConnectionException $throwable) {
            return new JsonResponse(['error' => UpstreamErrorText::sanitize($throwable->getMessage())], 502);
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
     * frontend only supplies the downloadId we already showed it, and the
     * connection its row was rendered from.
     */
    public function executeManualImport(ExecuteManualImportRequest $executeManualImportRequest, string $service, ManualImportResolver $manualImportResolver): RedirectResponse
    {
        $validated = $executeManualImportRequest->validated();
        $downloadId = (string) $validated['download_id'];
        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $connection = $this->resolvePinnedQueueConnection($serviceType, (int) $validated['service_connection_id']);

        if (! $connection instanceof ServiceConnection) {
            return $this->flashAndBack('error', $this->unavailablePinMessage($serviceType));
        }

        $arrClient = $this->clientFor($connection);

        try {
            $candidates = $arrClient->getManualImport(['downloadId' => $downloadId]);
        } catch (RequestException|ConnectionException $throwable) {
            return $this->flashAndBack('error', __('Could not enumerate import candidates: :msg', ['msg' => UpstreamErrorText::sanitize($throwable->getMessage())]));
        }

        $files = $manualImportResolver->toImportPayload($candidates, $service, $downloadId);
        if ($files === []) {
            return $this->flashAndBack('error', __('Sonarr/Radarr returned no importable files for this download.'));
        }

        try {
            $arrClient->runCommand('ManualImport', [
                'files' => $files,
                'importMode' => 'auto',
            ]);
        } catch (RequestException|ConnectionException $throwable) {
            return $this->flashAndBack('error', __('Manual import failed: :msg', ['msg' => UpstreamErrorText::sanitize($throwable->getMessage())]));
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

    /**
     * The connection the Grab-queue or History rows were rendered from.
     * Queue and history ids overlap between instances, so writes are pinned
     * to it; a deleted, deactivated or other-service pin resolves to null and
     * nothing is sent.
     */
    private function resolvePinnedQueueConnection(ServiceType $serviceType, int $serviceConnectionId): ?ServiceConnection
    {
        try {
            return ServiceConnection::resolvePinnedStrict(['service_connection_id' => $serviceConnectionId], $serviceType);
        } catch (InvalidArgumentException|ModelNotFoundException) {
            return null;
        }
    }

    private function unavailablePinMessage(ServiceType $serviceType): string
    {
        return __('That :service connection is unavailable — refresh and try again.', ['service' => $serviceType->label()]);
    }

    private function clientFor(ServiceConnection $serviceConnection): ArrClient
    {
        return $this->arrConnections->client($serviceConnection);
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

        $sonarr = ServiceConnection::findActive(ServiceType::Sonarr);
        $services['sonarr'] = $sonarr instanceof ServiceConnection;
        if ($sonarr instanceof ServiceConnection) {
            $rows = [...$rows, ...$this->fetchSonarr($sonarr, $errors)];
        }

        $radarr = ServiceConnection::findActive(ServiceType::Radarr);
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
    private function loadHistory(string $service, int $page, GrabbedHistoryCache $grabbedHistoryCache): array
    {
        $serviceType = $service === 'radarr' ? ServiceType::Radarr : ServiceType::Sonarr;
        $connection = ServiceConnection::findActive($serviceType);

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
                ? $this->arrConnections->client($connection)->getHistory([...$params, 'includeSeries' => 'true', 'includeEpisode' => 'true'])
                : $this->arrConnections->client($connection)->getHistory([...$params, 'includeMovie' => 'true']);
        } catch (RequestException|ConnectionException) {
            return [...$result, 'error' => sprintf('%s is unreachable right now — its history could not be loaded.', $serviceType->label())];
        }

        $records = is_array($payload['records'] ?? null) ? array_values(array_filter($payload['records'], is_array(...))) : [];

        $grabbedIds = [];

        foreach ($records as $record) {
            if (($record['eventType'] ?? null) !== 'grabbed' || ! is_int($record['id'] ?? null)) {
                continue;
            }

            $grabbedIds[] = $record['id'];
        }

        $grabbedHistoryCache->remember($connection, $grabbedIds);

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
        } catch (RequestException|ConnectionException) {
            $errors[] = sprintf('%s is unreachable right now — its queue could not be loaded.', ServiceType::Sonarr->label());

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
        } catch (RequestException|ConnectionException) {
            $errors[] = sprintf('%s is unreachable right now — its queue could not be loaded.', ServiceType::Radarr->label());

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
            'service_connection_id' => $serviceConnection->id,
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
            'error_message' => $this->upstreamText($record['errorMessage'] ?? null),
            'status_messages' => $this->statusMessages($record['statusMessages'] ?? null),
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
            'service_connection_id' => $serviceConnection->id,
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
            'error_message' => $this->upstreamText($record['errorMessage'] ?? null),
            'status_messages' => $this->statusMessages($record['statusMessages'] ?? null),
            'added' => $record['added'] ?? null,
            'quality' => $record['quality']['quality']['name'] ?? null,
            'download_id' => $record['downloadId'] ?? null,
        ];
    }

    /**
     * Queue rows echo what the arr says about a download, which routinely
     * carries absolute download and library paths; keep the words, lose the
     * paths and query strings. Blank or non-string text is dropped rather
     * than replaced by sanitize()'s "no usable description" filler.
     */
    private function upstreamText(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' ? UpstreamErrorText::sanitize($text) : null;
    }

    /**
     * @return list<array{title: string, messages: list<string>}>
     */
    private function statusMessages(mixed $statusMessages): array
    {
        if (! is_array($statusMessages)) {
            return [];
        }

        $sanitized = [];

        foreach ($statusMessages as $statusMessage) {
            if (! is_array($statusMessage)) {
                continue;
            }

            $messages = [];

            foreach (is_array($statusMessage['messages'] ?? null) ? $statusMessage['messages'] : [] as $message) {
                $text = $this->upstreamText($message);

                if ($text === null) {
                    continue;
                }

                $messages[] = $text;
            }

            $sanitized[] = [
                'title' => $this->upstreamText($statusMessage['title'] ?? null) ?? '',
                'messages' => $messages,
            ];
        }

        return $sanitized;
    }
}
