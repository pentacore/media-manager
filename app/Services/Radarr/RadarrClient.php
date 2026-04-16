<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Services\Arr\ArrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * @see https://raw.githubusercontent.com/Radarr/Radarr/develop/src/Radarr.Api.V3/openapi.json for up-to-date openApi Spec
 */
class RadarrClient extends ArrClient
{
    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getMovies(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/movie', $this->apiVersion))->throw()->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getMovieById(int $id): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/movie/%d', $this->apiVersion, $id))->throw()->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function addMovie(array $data): array
    {
        return $this->buildClient()->post(sprintf('/api/%s/movie', $this->apiVersion), $data)->throw()->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function updateMovie(int $id, array $data): array
    {
        return $this->buildClient()->put(sprintf('/api/%s/movie/%d', $this->apiVersion, $id), $data)->throw()->json();
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
        return $this->buildClient()->get(sprintf('/api/%s/movie/lookup', $this->apiVersion), ['term' => $query])->throw()->json();
    }
}
