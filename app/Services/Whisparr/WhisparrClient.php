<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Cache\Services\WhisparrCache;
use App\Enums\WhisparrVersion;
use App\Jobs\ExecuteActionRequest;
use App\Services\Arr\ArrClient;
use App\Services\Arr\SearchCommandRunner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Override;

/**
 * Unified Whisparr client. The connection's WhisparrVersion selects the
 * upstream API resource: `movie` for v3, `series` for v2/Eros. Both live
 * under `/api/v3`.
 */
class WhisparrClient extends ArrClient
{
    private ?WhisparrCache $whisparrCache = null;

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException|WhisparrUnexpectedResponse
     */
    public function getItems(): array
    {
        return $this->cache()->rememberList(
            'list',
            fn (): array => $this->arrayBody($this->buildClient()->get($this->resourcePath())->throw()),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException|WhisparrUnexpectedResponse
     */
    public function getItemById(int $id): array
    {
        return $this->cache()->rememberEntity(
            'item:'.$id,
            fn (): array => $this->arrayBody($this->buildClient()->get(sprintf('%s/%d', $this->resourcePath(), $id))->throw()),
        );
    }

    /**
     * The scenes (episodes) of one v2 site, cached per series. v3 has no
     * episode resource.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException|ConnectionException|WhisparrUnexpectedResponse
     */
    public function getEpisodes(int $seriesId): array
    {
        return $this->cache()->rememberList(
            'episodes:'.$seriesId,
            function () use ($seriesId): array {
                $body = $this->arrayBody($this->buildClient()->get(sprintf('/api/%s/episode', $this->apiVersion), ['seriesId' => $seriesId])->throw());

                // Same boundary sanitisation as ArrClient::getCalendar(): a
                // non-array entry is dropped here so callers can trust the
                // declared list<array<...>> shape.
                return array_values(array_filter($body, is_array(...)));
            },
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function addItem(array $data): array
    {
        return $this->buildClient()->post($this->resourcePath(), $data)->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function updateItem(int $id, array $data): array
    {
        return $this->buildClient()->put(sprintf('%s/%d', $this->resourcePath(), $id), $data)->throw()->json() ?? [];
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteItem(int $id, bool $deleteFiles = false): void
    {
        $query = http_build_query(['deleteFiles' => $deleteFiles ? 'true' : 'false']);
        $this->buildClient()
            ->delete(sprintf('%s/%d?%s', $this->resourcePath(), $id, $query))
            ->throw();
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException|WhisparrUnexpectedResponse
     */
    public function searchItems(string $query): array
    {
        return $this->cache()->rememberList(
            'search:'.md5($query),
            fn (): array => $this->arrayBody($this->buildClient()->get(sprintf('%s/lookup', $this->resourcePath()), ['term' => $query])->throw()),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException|WhisparrUnexpectedResponse
     */
    #[Override]
    public function getQualityProfiles(): array
    {
        return $this->cache()->rememberMetadata(
            'quality-profiles',
            fn (): array => $this->arrayBody($this->buildClient()->get(sprintf('/api/%s/qualityprofile', $this->apiVersion))->throw()),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    #[Override]
    public function getRootFolders(): array
    {
        return $this->cache()->rememberMetadata('root-folders', fn (): array => parent::getRootFolders());
    }

    /**
     * Start an automatic indexer search for one item: `SeriesSearch` on v2
     * (series-shaped), `MoviesSearch` on v3 (movie-shaped). Like a targeted
     * (non-library-wide) `search_media` command, this opts out only of the
     * generic HTTP-level retry (`runCommand(..., withRetry: false)`), so one
     * call never sends the search more than once over the wire. A
     * ConnectionException/5xx is still transient at the job level —
     * {@see ExecuteActionRequest} may retry the whole ActionRequest per its
     * `$tries` — because re-searching one item is harmless, unlike the
     * library-wide sweeps {@see SearchCommandRunner} makes permanent instead.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function searchItem(int $id): array
    {
        return $this->connection->whisparrVersion() === WhisparrVersion::V2
            ? $this->runCommand('SeriesSearch', ['seriesId' => $id], withRetry: false)
            : $this->runCommand('MoviesSearch', ['movieIds' => [$id]], withRetry: false);
    }

    /**
     * A 200 read whose body is not a JSON array or object (an SSO login
     * page, HTML, a scalar) is a failure, never an empty result: throwing
     * inside the cache closure also keeps it out of the cache.
     *
     * @return array<array-key, mixed>
     *
     * @throws WhisparrUnexpectedResponse
     */
    private function arrayBody(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new WhisparrUnexpectedResponse('Whisparr answered with a body that is not JSON data.');
        }

        return $body;
    }

    /**
     * `/api/v3/movie` (v3) or `/api/v3/series` (v2), per the connection config.
     */
    private function resourcePath(): string
    {
        return sprintf('/api/%s/%s', $this->apiVersion, $this->connection->whisparrVersion()->resource());
    }

    private function cache(): WhisparrCache
    {
        return $this->whisparrCache ??= new WhisparrCache($this->connection);
    }
}
