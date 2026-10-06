<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Cache\Services\RadarrCache;
use App\Services\Arr\ArrClient;
use App\Services\Arr\ArrWriteUnconfirmed;
use App\Support\Cache\Warmable;
use Carbon\CarbonImmutable;
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
            fn (): array => $this->fetchMovieById($id),
        );
    }

    /**
     * Uncached read for a write path. RadarrActions changes one field and
     * PUTs the whole movie back, so it must start from what Radarr holds now:
     * a snapshot cached by an earlier page view would revert whatever changed
     * in Radarr since.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchMovieById(int $id): array
    {
        return $this->jsonArray($this->buildClient()->get(sprintf('/api/%s/movie/%d', $this->apiVersion, $id))->throw());
    }

    /**
     * Uncached, concurrent reads of several movies, for a page that shows
     * what Radarr holds now for many cards at once. One attempt per movie;
     * null for a movie that could not be read (see fetchResourcesByIds()).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    public function fetchMoviesByIds(array $ids): array
    {
        return $this->fetchResourcesByIds('movie', $ids);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function addMovie(array $data): array
    {
        // Write — not cached; bust handled by RadarrActions.
        return $this->confirmedWrite($this->buildClient()->post(sprintf('/api/%s/movie', $this->apiVersion), $data)->throw());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function updateMovie(int $id, array $data): array
    {
        return $this->confirmedWrite($this->buildClient()->put(sprintf('/api/%s/movie/%d', $this->apiVersion, $id), $data)->throw());
    }

    /**
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function deleteMovie(int $id, bool $deleteFiles = false): void
    {
        $query = http_build_query(['deleteFiles' => $deleteFiles ? 'true' : 'false']);
        $this->confirmedWrite($this->buildClient()
            ->delete(sprintf('/api/%s/movie/%d?%s', $this->apiVersion, $id, $query))
            ->throw());
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
            fn (): array => $this->jsonArray($this->buildClient()->get(sprintf('/api/%s/movie/lookup', $this->apiVersion), ['term' => $query])->throw()),
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

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    #[Override]
    public function getTags(): array
    {
        return $this->cache()->rememberMetadata(
            'tags',
            fn (): array => parent::getTags(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    #[Override]
    public function getCalendar(CarbonImmutable $start, CarbonImmutable $end, bool $withRetry = true): array
    {
        return $this->cache()->rememberList(
            sprintf('calendar:%s:%s', $start->toDateString(), $end->toDateString()),
            fn (): array => parent::getCalendar($start, $end, $withRetry),
        );
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function wantedQuery(): array
    {
        return ['sortKey' => 'movieMetadata.digitalRelease'];
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
        return $this->jsonArray($this->buildClient()
            ->get(sprintf('/api/%s/moviefile', $this->apiVersion), ['movieId' => $movieId])
            ->throw());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getMovieFileById(int $movieFileId): array
    {
        return $this->jsonArray($this->buildClient()
            ->get(sprintf('/api/%s/moviefile/%d', $this->apiVersion, $movieFileId))
            ->throw());
    }

    /**
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function deleteMovieFile(int $movieFileId): void
    {
        $this->confirmedWrite($this->buildClient()
            ->delete(sprintf('/api/%s/moviefile/%d', $this->apiVersion, $movieFileId))
            ->throw());
    }

    /**
     * Toggle monitoring for a movie via the editor endpoint. Used to suppress
     * the arr's auto-redownload search while a subtitle replacement is in flight.
     *
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function setMovieMonitored(int $movieId, bool $monitored): void
    {
        $this->confirmedWrite($this->buildClient()
            ->put(sprintf('/api/%s/movie/editor', $this->apiVersion), [
                'movieIds' => [$movieId],
                'monitored' => $monitored,
            ])
            ->throw());
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchMovies(): array
    {
        return $this->jsonArray($this->buildClient()->get(sprintf('/api/%s/movie', $this->apiVersion))->throw());
    }

    private function cache(): RadarrCache
    {
        return $this->radarrCache ??= new RadarrCache($this->connection);
    }
}
