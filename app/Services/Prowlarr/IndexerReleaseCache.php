<?php

declare(strict_types=1);

namespace App\Services\Prowlarr;

use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers the Prowlarr search rows MediaManager showed, for as long as
 * Prowlarr keeps them in its own release cache (30 minutes), so a Grab can
 * only target a release MediaManager fetched from that connection.
 *
 * A release guid can carry a tracker passkey and Prowlarr's downloadUrl
 * embeds its API key, so neither reaches the browser: rows are exposed by
 * sha256(guid) ("release key") plus the indexer id, and the guid stays in
 * the cached row for the server-side grab. The display fields are the #236
 * allowlist.
 */
final readonly class IndexerReleaseCache
{
    public const int TTL_SECONDS = 1800;

    /**
     * Remembers every release from one search in a single `Cache::putMany()`
     * call rather than one round-trip per row. Only for a user who can grab
     * (admins): the cached guid is what a grab sends to Prowlarr.
     *
     * @param  list<array<string, mixed>>  $releases
     * @return list<array{key: string|null, indexer_id: int|null, title: mixed, indexer: mixed, size: mixed, seeders: mixed, age: mixed, publishDate: mixed}>
     */
    public function remember(ServiceConnection $serviceConnection, array $releases): array
    {
        $rows = [];
        $cacheEntries = [];

        foreach ($releases as $release) {
            $row = $this->displayRow($release);
            $guid = $release['guid'] ?? null;
            $indexerId = (int) ($release['indexerId'] ?? 0);

            if (! is_string($guid) || $guid === '' || $indexerId <= 0) {
                $rows[] = ['key' => null, 'indexer_id' => null, ...$row];

                continue;
            }

            $releaseKey = hash('sha256', $guid);

            $cacheEntries[$this->cacheKey($serviceConnection->id, $indexerId, $releaseKey)] = [
                'guid' => $guid,
                'indexer_id' => $indexerId,
                'title' => is_string($row['title']) ? $row['title'] : null,
                'indexer' => is_string($row['indexer']) ? $row['indexer'] : null,
            ];

            $rows[] = ['key' => $releaseKey, 'indexer_id' => $indexerId, ...$row];
        }

        if ($cacheEntries !== []) {
            Cache::putMany($cacheEntries, self::TTL_SECONDS);
        }

        return $rows;
    }

    /**
     * The same display rows without remembering anything, for a user who
     * cannot grab: their searches never fill the cache, and the null key
     * hides the Grab button.
     *
     * @param  list<array<string, mixed>>  $releases
     * @return list<array{key: null, indexer_id: null, title: mixed, indexer: mixed, size: mixed, seeders: mixed, age: mixed, publishDate: mixed}>
     */
    public function present(array $releases): array
    {
        return array_map(
            fn (array $release): array => ['key' => null, 'indexer_id' => null, ...$this->displayRow($release)],
            $releases,
        );
    }

    /**
     * The #236 display allowlist: everything a search row may show.
     *
     * @param  array<string, mixed>  $release
     * @return array{title: mixed, indexer: mixed, size: mixed, seeders: mixed, age: mixed, publishDate: mixed}
     */
    private function displayRow(array $release): array
    {
        return [
            'title' => $release['title'] ?? null,
            'indexer' => $release['indexer'] ?? null,
            'size' => $release['size'] ?? null,
            'seeders' => $release['seeders'] ?? null,
            'age' => $release['age'] ?? null,
            'publishDate' => $release['publishDate'] ?? null,
        ];
    }

    /**
     * The cached row, guid included — server-side use only.
     *
     * @return array{guid: string, indexer_id: int, title: string|null, indexer: string|null}|null
     */
    public function find(ServiceConnection $serviceConnection, int $indexerId, string $releaseKey): ?array
    {
        $row = Cache::get($this->cacheKey($serviceConnection->id, $indexerId, $releaseKey));

        if (! is_array($row) || ! is_string($row['guid'] ?? null) || ! is_int($row['indexer_id'] ?? null)) {
            return null;
        }

        return [
            'guid' => $row['guid'],
            'indexer_id' => $row['indexer_id'],
            'title' => is_string($row['title'] ?? null) ? $row['title'] : null,
            'indexer' => is_string($row['indexer'] ?? null) ? $row['indexer'] : null,
        ];
    }

    private function cacheKey(int $connectionId, int $indexerId, string $releaseKey): string
    {
        return sprintf('prowlarr-release:%d:%d:%s', $connectionId, $indexerId, $releaseKey);
    }
}
