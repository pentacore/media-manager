<?php

declare(strict_types=1);

namespace App\Services\Prowlarr;

use App\Cache\Services\ProwlarrCache;
use App\Services\Arr\ArrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Override;

/**
 * @see https://prowlarr.com/docs/api/ for API Spec
 */
class ProwlarrClient extends ArrClient
{
    #[Override]
    protected string $apiVersion = 'v1';

    private ?ProwlarrCache $prowlarrCache = null;

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
        return $this->cache()->rememberList(
            'search:'.md5($query.'|'.serialize($options)),
            function () use ($query, $options): array {
                $params = ['query' => $query, ...$options];

                return $this->buildClient()
                    ->get(sprintf('/api/%s/search', $this->apiVersion), $params)
                    ->throw()
                    ->json() ?? [];
            },
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function listIndexers(): array
    {
        return $this->cache()->rememberList(
            'indexers',
            fn (): array => $this->buildClient()
                ->get(sprintf('/api/%s/indexer', $this->apiVersion))
                ->throw()
                ->json() ?? [],
        );
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
        return $this->cache()->rememberList(
            'stats:'.md5(serialize([$indexerId, $sinceHours])),
            function () use ($indexerId, $sinceHours): array {
                $params = array_filter([
                    'indexers' => $indexerId,
                    'since' => $sinceHours !== null ? now()->subHours($sinceHours)->toISOString() : null,
                ], fn (int|string|null $v): bool => $v !== null);

                return $this->buildClient()
                    ->get(sprintf('/api/%s/indexerstats', $this->apiVersion), $params)
                    ->throw()
                    ->json() ?? [];
            },
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
    public function getDiskSpace(): array
    {
        return $this->cache()->rememberMetadata(
            'disk-space',
            fn (): array => parent::getDiskSpace(),
        );
    }

    private function cache(): ProwlarrCache
    {
        return $this->prowlarrCache ??= new ProwlarrCache($this->connection);
    }
}
