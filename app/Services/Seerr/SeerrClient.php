<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Cache\Services\SeerrCache;
use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * @see https://github.com/seerr-team/seerr — canonical Seerr repo
 * @see https://raw.githubusercontent.com/electather/seerr/develop/seerr-api.yml — OpenAPI spec (pending publication at seerr-team)
 */
class SeerrClient
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
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/request', $this->apiVersion), $params)->throw()->json() ?? [],
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
            fn (): array => $this->buildClient()
                ->get(sprintf('/api/%s/request/count', $this->apiVersion))
                ->throw()
                ->json() ?? [],
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
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/discover/movies', $this->apiVersion), $options)->throw()->json() ?? [],
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
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/discover/tv', $this->apiVersion), $options)->throw()->json() ?? [],
        );
    }

    private function cache(): SeerrCache
    {
        return $this->seerrCache ??= new SeerrCache($this->connection);
    }
}
