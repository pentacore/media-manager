<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Cache\Services\SonarrCache;
use App\Services\Arr\ArrClient;
use App\Support\Cache\Warmable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Override;

/**
 * @see https://sonarr.tv/docs/api/#v3 for API Spec
 * @see {@link open-api.2026-04-16.yaml} for up-to-date openApi Spec
 */
class SonarrClient extends ArrClient implements Warmable
{
    private ?SonarrCache $sonarrCache = null;

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getSeries(): array
    {
        return $this->cache()->rememberList(
            'list',
            fn (): array => $this->fetchSeries(),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getSeriesById(int $id): array
    {
        return $this->cache()->rememberEntity(
            'series:'.$id,
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/series/%d', $this->apiVersion, $id))->throw()->json() ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function addSeries(array $data): array
    {
        // Write — not cached; bust handled by SonarrActions.
        return $this->buildClient()->post(sprintf('/api/%s/series', $this->apiVersion), $data)->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function updateSeries(int $id, array $data): array
    {
        return $this->buildClient()->put(sprintf('/api/%s/series/%d', $this->apiVersion, $id), $data)->throw()->json() ?? [];
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteSeries(int $id, bool $deleteFiles = false): void
    {
        $query = http_build_query(['deleteFiles' => $deleteFiles ? 'true' : 'false']);
        $this->buildClient()
            ->delete(sprintf('/api/%s/series/%d?%s', $this->apiVersion, $id, $query))
            ->throw();
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function searchSeries(string $query): array
    {
        return $this->cache()->rememberList(
            'search:'.md5($query),
            fn (): array => $this->buildClient()->get(sprintf('/api/%s/series/lookup', $this->apiVersion), ['term' => $query])->throw()->json() ?? [],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getEpisodesBySeries(int $seriesId): array
    {
        return $this->cache()->rememberList(
            'episodes:'.$seriesId,
            fn (): array => $this->buildClient()
                ->get(sprintf('/api/%s/episode', $this->apiVersion), ['seriesId' => $seriesId])
                ->throw()
                ->json() ?? [],
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
        $cache->warmList('list', fn (): array => $this->fetchSeries());
        $cache->warmMetadata('quality-profiles', fn (): array => parent::getQualityProfiles());
        $cache->warmMetadata('root-folders', fn (): array => parent::getRootFolders());
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchSeries(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/series', $this->apiVersion))->throw()->json() ?? [];
    }

    private function cache(): SonarrCache
    {
        return $this->sonarrCache ??= new SonarrCache($this->connection);
    }
}
