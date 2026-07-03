<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Cache\Services\SeerrCache;
use App\Models\ServiceConnection;
use App\Support\Cache\Warmable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * @see https://github.com/seerr-team/seerr — canonical Seerr repo
 * @see https://raw.githubusercontent.com/electather/seerr/develop/seerr-api.yml — OpenAPI spec (pending publication at seerr-team)
 */
class SeerrClient implements Warmable
{
    protected string $apiVersion = 'v1';

    private ?SeerrCache $seerrCache = null;

    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Api-Key' => $this->connection->api_key])
            ->timeout(10)
            ->connectTimeout(3)
            ->withUserAgent('MediaManager/'.config('app.version').' '.class_basename($this))
            ->retry(
                times: 3,
                sleepMilliseconds: fn (int $attempt): int => $attempt * 500,
                when: fn (Throwable $throwable): bool => $throwable instanceof ConnectionException
                    || ($throwable instanceof RequestException && $throwable->response->serverError()),
                throw: false,
            );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getStatus(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/status', $this->apiVersion))->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getRequests(array $params = []): array
    {
        return $this->cache()->rememberList(
            'requests:'.md5(serialize($params)),
            fn (): array => $this->fetchRequests($params),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getRequestById(int $id): array
    {
        return $this->cache()->rememberEntity(
            'request:'.$id,
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/request/%d', $this->apiVersion, $id))->throw()->json() ?? [],
        );
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteRequest(int $id): void
    {
        $this->buildClient()->delete(sprintf('/api/%s/request/%d', $this->apiVersion, $id))->throw();
    }

    /**
     * PUT a full request body to Seerr — the upstream contract requires
     * mediaType + mediaId in every payload, so callers should merge
     * incoming changes onto the existing request before invoking this.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function updateRequest(int $id, array $payload): array
    {
        return $this->buildClient()
            ->put(sprintf('/api/%s/request/%d', $this->apiVersion, $id), $payload)
            ->throw()
            ->json() ?? [];
    }

    /**
     * Update a request's status to approve or decline.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function updateRequestStatus(int $id, string $status): array
    {
        if (! in_array($status, ['approve', 'decline'], true)) {
            throw new InvalidArgumentException(sprintf('Invalid request status "%s". Expected "approve" or "decline".', $status));
        }

        return $this->buildClient()
            ->post(sprintf('/api/%s/request/%d/%s', $this->apiVersion, $id, $status))
            ->throw()
            ->json() ?? [];
    }

    /**
     * Retry a failed request by resending it to Sonarr/Radarr.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function retryRequest(int $id): array
    {
        return $this->buildClient()
            ->post(sprintf('/api/%s/request/%d/retry', $this->apiVersion, $id))
            ->throw()
            ->json() ?? [];
    }

    /**
     * Fetch full movie details (title, overview, etc) by TMDB id.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getMovieDetails(int $tmdbId): array
    {
        return $this->cache()->rememberEntity(
            'movie-details:'.$tmdbId,
            fn (): array => $this->buildClient()
                ->get(sprintf('/api/%s/movie/%d', $this->apiVersion, $tmdbId))
                ->throw()
                ->json() ?? [],
        );
    }

    /**
     * Fetch full TV show details (name, overview, etc) by TMDB id.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getTvDetails(int $tmdbId): array
    {
        return $this->cache()->rememberEntity(
            'tv-details:'.$tmdbId,
            fn (): array => $this->buildClient()
                ->get(sprintf('/api/%s/tv/%d', $this->apiVersion, $tmdbId))
                ->throw()
                ->json() ?? [],
        );
    }

    /**
     * Fetch detail payloads for multiple movie / TV records in a single
     * concurrent batch via Laravel's HTTP pool. Skips entries whose
     * entity-cache slot is already populated, then issues every remaining
     * GET in parallel and writes successful responses back to the entity
     * cache so subsequent single-entity reads come back without a network
     * round-trip. Failed fetches are simply omitted from the result map.
     *
     * @param  array<int, array{0: string, 1: int}>  $pairs  Each entry is [mediaType, tmdbId] where mediaType is "movie" or "tv".
     * @return array<string, array<string, mixed>> Detail payloads keyed by "{mediaType}:{tmdbId}".
     */
    public function getMediaDetailsBatch(array $pairs): array
    {
        $cache = $this->cache();
        $results = [];
        $missing = [];

        foreach ($pairs as $pair) {
            [$mediaType, $tmdbId] = [$pair[0], (int) $pair[1]];

            if ($mediaType !== 'movie' && $mediaType !== 'tv') {
                continue;
            }

            $key = $mediaType.':'.$tmdbId;

            if (isset($results[$key])) {
                continue;
            }

            $cached = $cache->getEntity(($mediaType === 'movie' ? 'movie-details:' : 'tv-details:').$tmdbId);
            if (is_array($cached)) {
                $results[$key] = $cached;

                continue;
            }

            $missing[$key] = [$mediaType, $tmdbId];
        }

        if ($missing === []) {
            return $results;
        }

        $baseUrl = rtrim($this->connection->url, '/');
        $apiKey = $this->connection->api_key;
        $apiVersion = $this->apiVersion;
        $userAgent = 'MediaManager/'.config('app.version').' '.class_basename($this);

        try {
            $responses = Http::pool(function (Pool $pool) use ($missing, $baseUrl, $apiKey, $userAgent, $apiVersion): array {
                $requests = [];
                foreach ($missing as $key => [$mediaType, $tmdbId]) {
                    $requests[] = $pool
                        ->as($key)
                        ->baseUrl($baseUrl)
                        ->withHeaders(['X-Api-Key' => $apiKey])
                        ->withUserAgent($userAgent)
                        ->timeout(10)
                        ->connectTimeout(3)
                        ->get(sprintf('/api/%s/%s/%d', $apiVersion, $mediaType, $tmdbId));
                }

                return $requests;
            });
        } catch (Throwable) {
            // Pool-wide failure (DNS / network) — fall back to whatever we
            // already had cached. Caller will use placeholder titles.
            return $results;
        }

        foreach ($missing as $key => [$mediaType, $tmdbId]) {
            $response = $responses[$key] ?? null;
            if (! $response instanceof Response) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $detail = $response->json() ?? [];
            $results[$key] = $detail;
            $cache->putEntity(($mediaType === 'movie' ? 'movie-details:' : 'tv-details:').$tmdbId, $detail);
        }

        return $results;
    }

    /**
     * Get the request count summary.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getRequestCount(): array
    {
        return $this->cache()->rememberList(
            'request-count',
            fn (): array => $this->fetchRequestCount(),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function search(string $query): array
    {
        return $this->cache()->rememberList(
            'search:'.md5($query),
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/search', $this->apiVersion), ['query' => $query])->throw()->json() ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getUsers(array $params = []): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/user', $this->apiVersion), $params)->throw()->json() ?? [];
    }

    /**
     * Discover movies from the Seerr catalog. Pass query options like `genre`, `sortBy`, `page`.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function discoverMovies(array $options = []): array
    {
        return $this->cache()->rememberList(
            'discover:movies:'.md5(serialize($options)),
            fn (): array => $this->fetchDiscoverMovies($options),
        );
    }

    /**
     * Discover TV shows from the Seerr catalog. Pass query options like `genre`, `sortBy`, `page`.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function discoverTv(array $options = []): array
    {
        return $this->cache()->rememberList(
            'discover:tv:'.md5(serialize($options)),
            fn (): array => $this->fetchDiscoverTv($options),
        );
    }

    public function warm(): void
    {
        $cache = $this->cache();

        // Mirror the Requests page initial load (page 1, 50 per page, sorted
        // by added) for every tab the user can land on. RequestController
        // sends exactly these params shape — order matters because the cache
        // key is `md5(serialize($params))`.
        //
        // Tabs map to upstream filters as follows:
        //   All           — no `filter` key
        //   Pending       — filter=pending
        //   Requested     — filter=processing  (Seerr's name for "sent downstream")
        //   Now available — filter=available
        //   Completed     — filter=completed
        //   Approved      — walked locally over the unfiltered list (take=100 below)
        //   Declined      — walked locally over the unfiltered list (take=100 below)
        $upstreamFilters = [null, 'pending', 'processing', 'available', 'completed'];
        foreach ($upstreamFilters as $upstreamFilter) {
            $params = ['take' => 50, 'skip' => 0, 'sort' => 'added'];
            if ($upstreamFilter !== null) {
                $params['filter'] = $upstreamFilter;
            }

            $cache->warmList(
                'requests:'.md5(serialize($params)),
                fn (): array => $this->fetchRequests($params),
            );
        }

        // Approved / Declined fall back to RequestController::loadLocallyFilteredRequests,
        // which walks the unfiltered list at take=100 (LOCAL_FILTER_PAGE_SIZE).
        // Warming page 1 of that walk covers both tabs' first paint.
        $localWalkParams = ['take' => 100, 'skip' => 0, 'sort' => 'added'];
        $cache->warmList(
            'requests:'.md5(serialize($localWalkParams)),
            fn (): array => $this->fetchRequests($localWalkParams),
        );

        $cache->warmList('request-count', fn (): array => $this->fetchRequestCount());
        $cache->warmList('discover:movies:'.md5(serialize([])), fn (): array => $this->fetchDiscoverMovies([]));
        $cache->warmList('discover:tv:'.md5(serialize([])), fn (): array => $this->fetchDiscoverTv([]));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchRequests(array $params): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/request', $this->apiVersion), $params)->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchRequestCount(): array
    {
        return $this->buildClient()
            ->get(sprintf('/api/%s/request/count', $this->apiVersion))
            ->throw()
            ->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchDiscoverMovies(array $options): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/discover/movies', $this->apiVersion), $options)->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchDiscoverTv(array $options): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/discover/tv', $this->apiVersion), $options)->throw()->json() ?? [];
    }

    private function cache(): SeerrCache
    {
        return $this->seerrCache ??= new SeerrCache($this->connection);
    }
}
