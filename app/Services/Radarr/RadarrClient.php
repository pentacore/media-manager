<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Cache\Services\RadarrCache;
use App\Services\Arr\ArrClient;
use App\Support\Cache\Warmable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Override;

/**
 * @see https://raw.githubusercontent.com/Radarr/Radarr/develop/src/Radarr.Api.V3/openapi.json for up-to-date openApi Spec
 */
class RadarrClient extends ArrClient implements Warmable
{
    private ?RadarrCache $radarrCache = null;

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getMovies(): array
    {
        return $this->cache()->rememberList(
            'list',
            fn (): array => $this->fetchMovies(),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getMovieById(int $id): array
    {
        return $this->cache()->rememberEntity(
            'movie:'.$id,
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/movie/%d', $this->apiVersion, $id))->throw()->json() ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function addMovie(array $data): array
    {
        // Write — not cached; bust handled by RadarrActions.
        return $this->buildClient()->post(sprintf('/api/%s/movie', $this->apiVersion), $data)->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function updateMovie(int $id, array $data): array
    {
        return $this->buildClient()->put(sprintf('/api/%s/movie/%d', $this->apiVersion, $id), $data)->throw()->json() ?? [];
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteMovie(int $id, bool $deleteFiles = false): void
    {
        $query = http_build_query(['deleteFiles' => $deleteFiles ? 'true' : 'false']);
        $this->buildClient()
            ->delete(sprintf('/api/%s/movie/%d?%s', $this->apiVersion, $id, $query))
            ->throw();
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function searchMovies(string $query): array
    {
        return $this->cache()->rememberList(
            'search:'.md5($query),
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/movie/lookup', $this->apiVersion), ['term' => $query])->throw()->json() ?? [],
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
        return $this->cache()->rememberMetadata(
            'quality-profiles',
            fn (): array => parent::getQualityProfiles(),
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
        return $this->cache()->rememberMetadata(
            'root-folders',
            fn (): array => parent::getRootFolders(),
        );
    }

    public function warm(): void
    {
        $cache = $this->cache();
        $cache->warmList('list', fn (): array => $this->fetchMovies());
        $cache->warmMetadata('quality-profiles', fn (): array => parent::getQualityProfiles());
        $cache->warmMetadata('root-folders', fn (): array => parent::getRootFolders());
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getMovieFiles(int $movieId): array
    {
        return $this->buildClient()
            ->get(sprintf('/api/%s/moviefile', $this->apiVersion), ['movieId' => $movieId])
            ->throw()
            ->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getMovieFileById(int $movieFileId): array
    {
        return $this->buildClient()
            ->get(sprintf('/api/%s/moviefile/%d', $this->apiVersion, $movieFileId))
            ->throw()
            ->json() ?? [];
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteMovieFile(int $movieFileId): void
    {
        $this->buildClient()
            ->delete(sprintf('/api/%s/moviefile/%d', $this->apiVersion, $movieFileId))
            ->throw();
    }

    /**
     * Toggle monitoring for a movie via the editor endpoint. Used to suppress
     * the arr's auto-redownload search while a subtitle replacement is in flight.
     *
     * @throws RequestException|ConnectionException
     */
    public function setMovieMonitored(int $movieId, bool $monitored): void
    {
        $this->buildClient()
            ->put(sprintf('/api/%s/movie/editor', $this->apiVersion), [
                'movieIds' => [$movieId],
                'monitored' => $monitored,
            ])
            ->throw();
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchMovies(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/movie', $this->apiVersion))->throw()->json() ?? [];
    }

    private function cache(): RadarrCache
    {
        return $this->radarrCache ??= new RadarrCache($this->connection);
    }
}
