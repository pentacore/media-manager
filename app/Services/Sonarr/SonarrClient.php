<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Services\Arr\ArrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * @see https://sonarr.tv/docs/api/#v3 for API Spec
 * @see {@link open-api.2026-04-16.yaml} for up-to-date openApi Spec
 */
class SonarrClient extends ArrClient
{
    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getSeries(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/series', $this->apiVersion))->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getSeriesById(int $id): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/series/%d', $this->apiVersion, $id))->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function addSeries(array $data): array
    {
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
        return $this->buildClient()->get(sprintf('/api/%s/series/lookup', $this->apiVersion), ['term' => $query])->throw()->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getEpisodesBySeries(int $seriesId): array
    {
        return $this->buildClient()
            ->get(sprintf('/api/%s/episode', $this->apiVersion), ['seriesId' => $seriesId])
            ->throw()
            ->json() ?? [];
    }
}
