<?php

declare(strict_types=1);

namespace App\Services\Prowlarr;

use App\Services\Arr\ArrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * @see https://prowlarr.com/docs/api/ for API Spec
 */
class ProwlarrClient extends ArrClient
{
    protected string $apiVersion = 'v1';

    /**
     * Search across configured indexers.
     *
     * @param  array<string, mixed>  $options  Optional 'type' (search|tv-search|movie-search), 'indexerIds' (array of int), 'categories' (array of int), 'limit' (int).
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function searchIndexers(string $query, array $options = []): array
    {
        $params = ['query' => $query, ...$options];

        return $this->buildClient()
            ->get(sprintf('/api/%s/search', $this->apiVersion), $params)
            ->throw()
            ->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function listIndexers(): array
    {
        return $this->buildClient()
            ->get(sprintf('/api/%s/indexer', $this->apiVersion))
            ->throw()
            ->json() ?? [];
    }

    /**
     * Test a single configured indexer.
     *
     * @return array{success: bool, errors: array<int, array<string, mixed>>}
     *
     * @throws ConnectionException
     */
    public function testIndexer(int $indexerId): array
    {
        $response = $this->buildClient()
            ->post(sprintf('/api/%s/indexer/%d/test', $this->apiVersion, $indexerId));

        if ($response->successful()) {
            return ['success' => true, 'errors' => []];
        }

        return [
            'success' => false,
            'errors' => $response->json() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getIndexerStats(?int $indexerId = null, ?int $sinceHours = null): array
    {
        $params = array_filter([
            'indexers' => $indexerId,
            'since' => $sinceHours !== null ? now()->subHours($sinceHours)->toISOString() : null,
        ], fn ($v): bool => $v !== null);

        return $this->buildClient()
            ->get(sprintf('/api/%s/indexerstats', $this->apiVersion), $params)
            ->throw()
            ->json() ?? [];
    }
}
