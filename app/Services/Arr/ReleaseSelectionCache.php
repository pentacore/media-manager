<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers the interactive-search rows MediaManager showed a member, for as
 * long as Sonarr/Radarr keep the release in their own cache (30 minutes), so
 * a grab can only target a release MediaManager fetched — and its Action
 * Queue card shows upstream facts, not browser-sent text.
 *
 * The raw release guid can carry a private tracker passkey, so it never
 * reaches the browser: rows are keyed and exposed by sha256(guid) ("release
 * key") instead, and the guid stays server-side in the cached row for the
 * executor payload. A grab is also bound to the search that produced the
 * row — the cached row records the item (and season/episode) it was found
 * for, so a grab against a different item is refused.
 */
final readonly class ReleaseSelectionCache
{
    public const int TTL_SECONDS = 1800;

    /**
     * @param  array<string, mixed>  $release
     * @return array{key: string, indexer_id: int, title: string, quality: string|null, size: int, age_hours: float|null, peers: int|null, protocol: string|null, indexer: string|null, rejected: bool, rejections: list<string>}|null
     */
    public function remember(ServiceConnection $serviceConnection, array $release, int $itemId, ?int $seasonNumber = null, ?int $episodeId = null): ?array
    {
        $row = $this->present($release);

        if ($row === null) {
            return null;
        }

        $releaseKey = hash('sha256', $row['guid']);
        $cached = [
            ...$row,
            'target' => ['item_id' => $itemId, 'season_number' => $seasonNumber, 'episode_id' => $episodeId],
        ];

        Cache::put($this->key($serviceConnection->id, $row['indexer_id'], $releaseKey), $cached, self::TTL_SECONDS);

        unset($row['guid']);

        return ['key' => $releaseKey, ...$row];
    }

    /**
     * Returns the full cached row (guid and search target included) for
     * server-side use only — never expose it to the browser.
     *
     * @return array<string, mixed>|null
     */
    public function find(ServiceConnection $serviceConnection, int $indexerId, string $releaseKey): ?array
    {
        $row = Cache::get($this->key($serviceConnection->id, $indexerId, $releaseKey));

        return is_array($row) ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array{guid: string, indexer_id: int, title: string, quality: string|null, size: int, age_hours: float|null, peers: int|null, protocol: string|null, indexer: string|null, rejected: bool, rejections: list<string>}|null
     */
    private function present(array $release): ?array
    {
        $guid = $release['guid'] ?? null;
        $indexerId = (int) ($release['indexerId'] ?? 0);
        $title = $release['title'] ?? null;

        if (! is_string($guid) || $guid === '' || $indexerId <= 0 || ! is_string($title) || $title === '') {
            return null;
        }

        $protocol = is_string($release['protocol'] ?? null) ? $release['protocol'] : null;
        $rejections = array_values(array_filter((array) ($release['rejections'] ?? []), is_string(...)));
        $quality = $release['quality']['quality']['name'] ?? null;

        return [
            'guid' => $guid,
            'indexer_id' => $indexerId,
            'title' => $title,
            'quality' => is_string($quality) ? $quality : null,
            'size' => (int) ($release['size'] ?? 0),
            'age_hours' => is_numeric($release['ageHours'] ?? null) ? round((float) $release['ageHours'], 1) : null,
            // ReleaseResource has no grabs count; peers are torrent seeders.
            'peers' => $protocol === 'torrent' && is_numeric($release['seeders'] ?? null) ? (int) $release['seeders'] : null,
            'protocol' => $protocol,
            'indexer' => is_string($release['indexer'] ?? null) ? $release['indexer'] : null,
            'rejected' => (bool) ($release['rejected'] ?? $rejections !== []),
            'rejections' => $rejections,
        ];
    }

    private function key(int $connectionId, int $indexerId, string $releaseKey): string
    {
        return sprintf('library-release:%d:%d:%s', $connectionId, $indexerId, $releaseKey);
    }
}
