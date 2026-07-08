<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Cache\Services\SeerrCache;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Seerr\SeerrTitleResolver;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequestController extends Controller
{
    public function __construct(
        private readonly SeerrTitleResolver $seerrTitleResolver,
    ) {}

    /**
     * Allowed status filters mapped to the Seerr `filter` query param.
     * Approved and declined are not native Seerr filter values, so they
     * are handled by walking the unfiltered list and matching on the
     * request `status` field. See LOCAL_STATUS_VALUES.
     */
    private const array STATUS_FILTERS = [
        'all' => null,
        'pending' => 'pending',
        'approved' => null,
        'processing' => 'processing',
        'available' => 'available',
        'completed' => 'completed',
        'declined' => null,
    ];

    /**
     * Request statuses that have to be filtered locally because Seerr's
     * /request endpoint does not understand them as filter strings.
     * Seerr's MediaRequestStatus: 1=PENDING, 2=APPROVED, 3=DECLINED, 4=FAILED.
     */
    private const array LOCAL_STATUS_VALUES = [
        'approved' => 2,
        'declined' => 3,
    ];

    private const int LOCAL_FILTER_PAGE_SIZE = 100;

    private const int LOCAL_FILTER_MAX_PAGES = 20;

    public function index(Request $request): Response|RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $status = (string) $request->query('status', 'pending');

        if (! array_key_exists($status, self::STATUS_FILTERS)) {
            $status = 'pending';
        }

        return Inertia::render('Seerr/Requests', [
            'connection' => ['url' => $connection->linkUrl()],
            'filters' => ['page' => $page, 'status' => $status],
            'requests' => Inertia::defer(fn (): array => $this->loadRequests($connection, $page, $perPage, $status)),
            'summary' => Inertia::defer(fn (): array => $this->loadSummary($connection)),
        ]);
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
            new SeerrClient($connection)->deleteRequest($id);
            new SeerrCache($connection)->bustAll();
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to delete request.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request deleted.')]);

        return to_route('media.requests.index');
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->updateStatus($id, 'approve', __('Request approved.'), __('Failed to approve request.'));
    }

    public function decline(int $id): RedirectResponse
    {
        return $this->updateStatus($id, 'decline', __('Request declined.'), __('Failed to decline request.'));
    }

    public function retry(int $id): RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
            new SeerrClient($connection)->retryRequest($id);
            new SeerrCache($connection)->bustAll();
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to retry request.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request retry triggered.')]);

        return back();
    }

    /**
     * Returns the current request snapshot plus the quality profiles and
     * root folders the matching Sonarr/Radarr exposes — Seerr does NOT
     * expose its own /service config endpoint we can use, so we read
     * profile lists straight from the *arr that owns the media.
     */
    public function editOptions(int $id): JsonResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return new JsonResponse(['error' => 'no_seerr_connection'], 422);
        }

        try {
            $request = new SeerrClient($connection)->getRequestById($id);
        } catch (RequestException|ConnectionException $throwable) {
            return new JsonResponse(['error' => $throwable->getMessage()], 502);
        }

        $mediaType = (string) ($request['media']['mediaType'] ?? $request['type'] ?? '');
        $arrClient = $this->resolveArrFor($mediaType);
        if (! $arrClient instanceof ArrClient) {
            return new JsonResponse(['error' => 'no_arr_for_media_type'], 422);
        }

        try {
            $profiles = $arrClient->getQualityProfiles();
            $rootFolders = $arrClient->getRootFolders();
        } catch (RequestException|ConnectionException $throwable) {
            return new JsonResponse(['error' => 'arr_unreachable: '.$throwable->getMessage()], 502);
        }

        return new JsonResponse([
            'media_type' => $mediaType,
            'current' => [
                'profile_id' => $request['profileId'] ?? null,
                'root_folder' => $request['rootFolder'] ?? null,
                'server_id' => $request['serverId'] ?? null,
                'is4k' => (bool) ($request['is4k'] ?? false),
                'media_id' => $request['media']['id'] ?? null,
            ],
            'profiles' => array_values(array_map(
                static fn (array $profile): array => [
                    'id' => $profile['id'] ?? null,
                    'name' => $profile['name'] ?? null,
                ],
                $profiles,
            )),
            'root_folders' => array_values(array_map(
                static fn (array $folder): array => [
                    'path' => $folder['path'] ?? null,
                    'free_space' => $folder['freeSpace'] ?? null,
                ],
                $rootFolders,
            )),
        ]);
    }

    /**
     * Apply a profile / root folder change to a Seerr request. We re-read
     * the existing row first so the upstream PUT — which requires the
     * full media descriptor on every call — keeps mediaType + mediaId +
     * serverId + is4k aligned with Seerr's stored values; the caller can
     * only override the two fields they're supposed to be editing.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer'],
            'root_folder' => ['required', 'string'],
        ]);

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }

        $seerrClient = new SeerrClient($connection);

        try {
            $existing = $seerrClient->getRequestById($id);
        } catch (RequestException|ConnectionException $throwable) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to load request: :msg', ['msg' => $throwable->getMessage()])]);

            return back();
        }

        $mediaType = $existing['media']['mediaType'] ?? $existing['type'] ?? null;
        $mediaId = $existing['media']['id'] ?? null;
        if ($mediaType === null || $mediaId === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Request is missing media metadata.')]);

            return back();
        }

        $payload = [
            'mediaType' => $mediaType,
            'mediaId' => (int) $mediaId,
            'serverId' => $existing['serverId'] ?? null,
            'profileId' => (int) $validated['profile_id'],
            'rootFolder' => $validated['root_folder'],
            'is4k' => (bool) ($existing['is4k'] ?? false),
        ];

        if (isset($existing['languageProfileId'])) {
            $payload['languageProfileId'] = $existing['languageProfileId'];
        }

        if (isset($existing['tags']) && is_array($existing['tags'])) {
            $payload['tags'] = $existing['tags'];
        }

        try {
            $seerrClient->updateRequest($id, $payload);
            new SeerrCache($connection)->bustAll();
        } catch (RequestException|ConnectionException $throwable) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Seerr rejected the update: :msg', ['msg' => $throwable->getMessage()])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request updated.')]);

        return back();
    }

    private function resolveArrFor(string $mediaType): ?ArrClient
    {
        $arrType = match ($mediaType) {
            'tv' => ServiceType::Sonarr,
            'movie' => ServiceType::Radarr,
            default => null,
        };

        if ($arrType === null) {
            return null;
        }

        try {
            $connection = ServiceConnection::resolveActive($arrType);
        } catch (ModelNotFoundException) {
            return null;
        }

        return $arrType === ServiceType::Sonarr
            ? new SonarrClient($connection)
            : new RadarrClient($connection);
    }

    /** Statuses the bulk-clear UI is allowed to target. */
    public const array CLEARABLE_STATUSES = ['completed', 'available', 'declined', 'failed'];

    /** Cap on rows deleted in a single clear call so a misclick can't wipe thousands silently. */
    private const int CLEAR_HARD_LIMIT = 500;

    /**
     * Bulk-delete every Seerr request matching a given status. Walks the
     * paginated upstream list, deletes each match, and busts the cache
     * once at the end. Returns to the page with a count toast.
     */
    public function clear(Request $request): RedirectResponse
    {
        $status = (string) $request->input('status');

        if (! in_array($status, self::CLEARABLE_STATUSES, true)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Invalid status for bulk clear.')]);

            return back();
        }

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }

        $seerrClient = new SeerrClient($connection);

        try {
            $ids = $this->collectIdsForStatus($seerrClient, $status, self::CLEAR_HARD_LIMIT);
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to enumerate Seerr requests.')]);

            return back();
        }

        if ($ids === []) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Nothing to clear — no :status requests.', ['status' => $status])]);

            return back();
        }

        $deleted = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $seerrClient->deleteRequest($id);
                $deleted++;
            } catch (RequestException|ConnectionException) {
                $failed++;
            }
        }

        new SeerrCache($connection)->bustAll();

        if ($failed === 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Cleared :n :status request(s).', ['n' => $deleted, 'status' => $status])]);
        } else {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('Cleared :ok of :total :status request(s); :fail failed.', ['ok' => $deleted, 'total' => $deleted + $failed, 'fail' => $failed, 'status' => $status])]);
        }

        return to_route('media.requests.index', ['status' => $status]);
    }

    /**
     * Walk Seerr's paginated /request list and collect IDs whose effective
     * status matches the target. Native filter strings (`completed`,
     * `available`, `failed`) are passed straight to Seerr; statuses Seerr
     * doesn't filter on (`declined` → status code 3) are matched locally.
     *
     * @return array<int, int>
     *
     * @throws RequestException|ConnectionException
     */
    private function collectIdsForStatus(SeerrClient $seerrClient, string $status, int $hardLimit): array
    {
        $perPage = self::LOCAL_FILTER_PAGE_SIZE;
        $params = ['take' => $perPage, 'sort' => 'added'];

        $upstreamFilter = match ($status) {
            'completed' => 'completed',
            'available' => 'available',
            'failed' => 'failed',
            default => null,
        };

        $localStatusValue = match ($status) {
            'declined' => 3,
            default => null,
        };

        if ($upstreamFilter !== null) {
            $params['filter'] = $upstreamFilter;
        }

        $ids = [];

        for ($pageIndex = 0; $pageIndex < self::LOCAL_FILTER_MAX_PAGES; $pageIndex++) {
            $params['skip'] = $pageIndex * $perPage;
            $response = $seerrClient->getRequests($params);

            $rows = is_array($response['results'] ?? null) ? $response['results'] : [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ($localStatusValue !== null && (int) ($row['status'] ?? 0) !== $localStatusValue) {
                    continue;
                }

                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                    if (count($ids) >= $hardLimit) {
                        return $ids;
                    }
                }
            }

            $pageInfo = is_array($response['pageInfo'] ?? null) ? $response['pageInfo'] : [];
            $totalPages = (int) ($pageInfo['pages'] ?? 0);
            if ($totalPages > 0 && $pageIndex + 1 >= $totalPages) {
                break;
            }
        }

        return $ids;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function loadRequests(ServiceConnection $serviceConnection, int $page, int $perPage, string $status = 'pending'): array
    {
        if (array_key_exists($status, self::LOCAL_STATUS_VALUES)) {
            return $this->loadLocallyFilteredRequests($serviceConnection, $page, $perPage, $status);
        }

        $seerrClient = new SeerrClient($serviceConnection);

        $params = [
            'take' => $perPage,
            'skip' => ($page - 1) * $perPage,
            'sort' => 'added',
        ];

        $filter = self::STATUS_FILTERS[$status] ?? null;
        if ($filter !== null) {
            $params['filter'] = $filter;
        }

        try {
            $response = $seerrClient->getRequests($params);
        } catch (RequestException|ConnectionException) {
            return [
                'data' => [],
                'meta' => ['current_page' => $page, 'last_page' => 1, 'total' => 0, 'per_page' => $perPage],
            ];
        }

        $results = is_array($response['results'] ?? null) ? $response['results'] : [];
        $pageInfo = is_array($response['pageInfo'] ?? null) ? $response['pageInfo'] : [];

        $media = $this->seerrTitleResolver->resolve($serviceConnection, $seerrClient, $results);

        return [
            'data' => array_map(fn (array $req): array => $this->mapRequest($req, $media), $results),
            'meta' => [
                'current_page' => (int) ($pageInfo['page'] ?? $page),
                'last_page' => (int) ($pageInfo['pages'] ?? 1),
                'total' => (int) ($pageInfo['results'] ?? count($results)),
                'per_page' => (int) ($pageInfo['pageSize'] ?? $perPage),
            ],
        ];
    }

    /**
     * Walk Seerr's unfiltered request list and keep only rows whose
     * `status` matches the requested approved/declined view. Stops as
     * soon as enough rows have been collected for the current page or
     * the upstream list is exhausted. The total count comes from
     * /request/count so pagination still reports accurate bounds even
     * when we short-circuit before walking everything.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function loadLocallyFilteredRequests(ServiceConnection $serviceConnection, int $page, int $perPage, string $status): array
    {
        $statusValue = self::LOCAL_STATUS_VALUES[$status];
        $seerrClient = new SeerrClient($serviceConnection);
        $needed = $page * $perPage;
        $matched = [];

        for ($pageIndex = 0; $pageIndex < self::LOCAL_FILTER_MAX_PAGES; $pageIndex++) {
            try {
                $response = $seerrClient->getRequests([
                    'take' => self::LOCAL_FILTER_PAGE_SIZE,
                    'skip' => $pageIndex * self::LOCAL_FILTER_PAGE_SIZE,
                    'sort' => 'added',
                ]);
            } catch (RequestException|ConnectionException) {
                break;
            }

            $rows = is_array($response['results'] ?? null) ? $response['results'] : [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ((int) ($row['status'] ?? 0) === $statusValue) {
                    $matched[] = $row;
                    if (count($matched) >= $needed) {
                        break 2;
                    }
                }
            }

            $pageInfo = is_array($response['pageInfo'] ?? null) ? $response['pageInfo'] : [];
            $totalPages = (int) ($pageInfo['pages'] ?? 0);
            if ($totalPages > 0 && $pageIndex + 1 >= $totalPages) {
                break;
            }
        }

        $window = array_slice($matched, ($page - 1) * $perPage, $perPage);
        $media = $this->seerrTitleResolver->resolve($serviceConnection, $seerrClient, $window);

        try {
            $counts = $seerrClient->getRequestCount();
            $total = (int) ($counts[$status] ?? count($matched));
        } catch (RequestException|ConnectionException) {
            $total = count($matched);
        }

        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'data' => array_map(fn (array $req): array => $this->mapRequest($req, $media), $window),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Status keys we surface as request tabs. Sourced verbatim from
     * Seerr's /request/count payload (minus the movie/tv breakdown,
     * which is media-type, not status).
     */
    private const array SUMMARY_STATUS_KEYS = [
        'pending',
        'approved',
        'processing',
        'available',
        'completed',
        'declined',
    ];

    /**
     * @return array<string, int>
     */
    private function loadSummary(ServiceConnection $serviceConnection): array
    {
        try {
            $counts = new SeerrClient($serviceConnection)->getRequestCount();
        } catch (RequestException|ConnectionException) {
            return $this->emptySummary();
        }

        $summary = ['total' => (int) ($counts['total'] ?? 0)];

        foreach (self::SUMMARY_STATUS_KEYS as $key) {
            $summary[$key] = (int) ($counts[$key] ?? 0);
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        $summary = ['total' => 0];

        foreach (self::SUMMARY_STATUS_KEYS as $key) {
            $summary[$key] = 0;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $req
     * @param  array<string, array{title: string, poster_path: ?string}>  $media
     * @return array<string, mixed>
     */
    private function mapRequest(array $req, array $media): array
    {
        $mediaType = $req['type'] ?? ($req['media']['mediaType'] ?? null);
        $tmdbId = $req['media']['tmdbId'] ?? null;

        // When the underlying media is fully AVAILABLE in Seerr's local DB,
        // surface that to the UI in place of the raw request status. The
        // Vue side keys "Open in Emby" / "Now available" off status === 5,
        // so without this an approved-and-grabbed item would still render
        // as plain "Approved" with a Seerr-only View detail link.
        $mediaStatus = $req['media']['status'] ?? null;
        $status = $mediaStatus === 5 ? 5 : ($req['status'] ?? null);

        return [
            'id' => $req['id'] ?? null,
            'status' => $status,
            'media_type' => $mediaType,
            'media_title' => $this->seerrTitleResolver->titleFor($req, $media),
            'poster_path' => $this->seerrTitleResolver->posterPathFor($req, $media),
            'tmdb_id' => $tmdbId,
            'tvdb_id' => $req['media']['tvdbId'] ?? null,
            'requester' => $req['requestedBy']['displayName'] ?? ($req['requestedBy']['username'] ?? null),
            'created_at' => $req['createdAt'] ?? null,
            'updated_at' => $req['updatedAt'] ?? null,
        ];
    }

    private function updateStatus(int $id, string $status, string $successMessage, string $failureMessage): RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
            new SeerrClient($connection)->updateRequestStatus($id, $status);
            new SeerrCache($connection)->bustAll();
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $failureMessage]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $successMessage]);

        return back();
    }

    private function noConnectionRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Seerr connection configured.')]);

        return to_route('dashboard');
    }
}
