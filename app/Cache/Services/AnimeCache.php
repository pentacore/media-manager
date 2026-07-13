<?php

declare(strict_types=1);

namespace App\Cache\Services;

/**
 * Household-keyed cache for assembled seasonal anime lists (external
 * AniList/Jikan calls plus id mapping). Not tied to a ServiceConnection.
 *
 * Buckets are repurposed: `list` holds the still-churning current/future
 * season, `metadata` holds immutable past seasons.
 */
class AnimeCache extends BaseServiceCache
{
    protected function service(): string
    {
        return 'anime';
    }

    protected function connectionId(): ?int
    {
        return null;
    }

    /**
     * @return array{list: int, entity: int, metadata: int}
     */
    protected function ttls(): array
    {
        $ttl = config('mediamanager.anime.cache_ttl');

        return [
            'list' => (int) $ttl['current'],
            'entity' => (int) $ttl['current'],
            'metadata' => (int) $ttl['past'],
        ];
    }
}
