<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Cache\Services\SonarrCache;
use App\Services\Arr\ArrClient;
use App\Services\Arr\ArrWriteUnconfirmed;
use App\Support\Cache\Warmable;
use Carbon\CarbonImmutable;
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
            fn (): array => $this->fetchSeriesById($id),
        );
    }

    /**
     * Uncached read for a write path. SonarrActions changes one field and
     * PUTs the whole series back, so it must start from what Sonarr holds
     * now: a snapshot cached by an earlier page view would revert whatever
     * changed in Sonarr since.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchSeriesById(int $id): array
    {
        return $this->jsonArray($this->buildClient()->get(sprintf('/api/%s/series/%d', $this->apiVersion, $id))->throw());
    }

    /**
     * Uncached, concurrent reads of several series, for a page that shows
     * what Sonarr holds now for many cards at once. One attempt per series;
     * null for a series that could not be read (see fetchResourcesByIds()).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    public function fetchSeriesByIds(array $ids): array
    {
        return $this->fetchResourcesByIds('series', $ids);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function addSeries(array $data): array
    {
        // Write — not cached; bust handled by SonarrActions.
        return $this->confirmedWrite($this->buildClient()->post(sprintf('/api/%s/series', $this->apiVersion), $data)->throw());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function updateSeries(int $id, array $data): array
    {
        return $this->confirmedWrite($this->buildClient()->put(sprintf('/api/%s/series/%d', $this->apiVersion, $id), $data)->throw());
    }

    /**
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function deleteSeries(int $id, bool $deleteFiles = false): void
    {
        $query = http_build_query(['deleteFiles' => $deleteFiles ? 'true' : 'false']);
        $this->confirmedWrite($this->buildClient()
            ->delete(sprintf('/api/%s/series/%d?%s', $this->apiVersion, $id, $query))
            ->throw());
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
            fn (): array => $this->jsonArray($this->buildClient()->get(sprintf('/api/%s/series/lookup', $this->apiVersion), ['term' => $query])->throw()),
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
            fn (): array => $this->fetchEpisodesBySeries($seriesId),
        );
    }

    /**
     * Uncached episode list, for a check that must not trust a list cached
     * before Sonarr added an episode (SonarrEpisodeOwnership).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchEpisodesBySeries(int $seriesId): array
    {
        return $this->jsonArray($this->buildClient()
            ->get(sprintf('/api/%s/episode', $this->apiVersion), ['seriesId' => $seriesId])
            ->throw());
    }

    /**
     * Normalize Sonarr v4's mappedEpisodeInfo resources to the episodeIds field
     * used by the replacement safety boundary. Keep the complete release row so
     * a later approved grab can post Sonarr's original resource back unchanged
     * apart from the documented override flag.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    #[Override]
    public function getReleases(array $params): array
    {
        return array_values(array_map(
            $this->normalizeReleaseMapping(...),
            array_filter(parent::getReleases($params), is_array(...)),
        ));
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
    protected function calendarQuery(): array
    {
        return ['includeSeries' => 'true'];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function wantedQuery(): array
    {
        return ['sortKey' => 'episodes.airDateUtc', 'includeSeries' => 'true'];
    }

    public function warm(): void
    {
        $cache = $this->cache();
        $cache->warmList('list', fn (): array => $this->fetchSeries());
        $cache->warmMetadata('quality-profiles', fn (): array => parent::getQualityProfiles());
        $cache->warmMetadata('root-folders', fn (): array => parent::getRootFolders());
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private function normalizeReleaseMapping(array $release): array
    {
        if (is_array($release['episodeIds'] ?? null)) {
            return $release;
        }

        $mappedEpisodeInfo = is_array($release['mappedEpisodeInfo'] ?? null)
            ? $release['mappedEpisodeInfo']
            : [];
        $episodeIds = [];

        foreach ($mappedEpisodeInfo as $episode) {
            $episodeId = is_array($episode) ? ($episode['id'] ?? null) : null;

            if (is_int($episodeId) && $episodeId > 0) {
                $episodeIds[$episodeId] = $episodeId;
            }
        }

        sort($episodeIds, SORT_NUMERIC);
        $release['episodeIds'] = array_values($episodeIds);

        return $release;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getEpisodeFiles(int $seriesId): array
    {
        return $this->jsonArray($this->buildClient()
            ->get(sprintf('/api/%s/episodefile', $this->apiVersion), ['seriesId' => $seriesId])
            ->throw());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getEpisodeFileById(int $episodeFileId): array
    {
        return $this->jsonArray($this->buildClient()
            ->get(sprintf('/api/%s/episodefile/%d', $this->apiVersion, $episodeFileId))
            ->throw());
    }

    /**
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function deleteEpisodeFile(int $episodeFileId): void
    {
        $this->confirmedWrite($this->buildClient()
            ->delete(sprintf('/api/%s/episodefile/%d', $this->apiVersion, $episodeFileId))
            ->throw());
    }

    /**
     * Toggle episode-level monitoring for the given episodes. Used to suppress
     * the arr's auto-redownload search while a subtitle replacement is in flight.
     *
     * @param  array<int, int>  $episodeIds
     *
     * @throws ArrWriteUnconfirmed|RequestException|ConnectionException
     */
    public function setEpisodesMonitored(array $episodeIds, bool $monitored): void
    {
        $this->confirmedWrite($this->buildClient()
            ->put(sprintf('/api/%s/episode/monitor', $this->apiVersion), [
                'episodeIds' => array_values($episodeIds),
                'monitored' => $monitored,
            ])
            ->throw());
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchSeries(): array
    {
        return $this->jsonArray($this->buildClient()->get(sprintf('/api/%s/series', $this->apiVersion))->throw());
    }

    private function cache(): SonarrCache
    {
        return $this->sonarrCache ??= new SonarrCache($this->connection);
    }
}
