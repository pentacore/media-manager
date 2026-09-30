<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Cache\Services\WhisparrCache;
use App\Enums\WhisparrVersion;
use App\Services\Arr\ArrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
     * @throws RequestException|ConnectionException
     */
    public function getItems(): array
    {
        return $this->cache()->rememberList(
            'list',
            fn (): array => $this->buildClient()->get($this->resourcePath())->throw()->json() ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getItemById(int $id): array
    {
        return $this->cache()->rememberEntity(
            'item:'.$id,
            fn (): array => $this->buildClient()->get(sprintf('%s/%d', $this->resourcePath(), $id))->throw()->json() ?? [],
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
     * @throws RequestException|ConnectionException
     */
    public function searchItems(string $query): array
    {
        return $this->cache()->rememberList(
            'search:'.md5($query),
            fn (): array => $this->buildClient()->get(sprintf('%s/lookup', $this->resourcePath()), ['term' => $query])->throw()->json() ?? [],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    #[Override]
    public function getQualityProfiles(): array
    {
        return $this->cache()->rememberMetadata('quality-profiles', fn (): array => parent::getQualityProfiles());
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
     * (series-shaped), `MoviesSearch` on v3 (movie-shaped). Like
     * `search_media`, the command opts out of the transparent retry — a lost
     * response must not fire the search three times.
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
