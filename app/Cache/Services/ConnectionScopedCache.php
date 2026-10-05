<?php

declare(strict_types=1);

namespace App\Cache\Services;

use App\Models\ServiceConnection;

/**
 * Base for the caches scoped to one ServiceConnection that use the shared
 * `mediamanager.cache.ttl` buckets (Sonarr, Radarr, SABnzbd, Seerr,
 * Prowlarr, Whisparr). Entries are keyed and tagged `{service}:{connection
 * id}`, so a subclass declares only its service slug. BazarrCache (its own
 * connection checks and TTLs) and the household caches (Anime, Tmdb, Trakt)
 * extend BaseServiceCache directly.
 */
abstract class ConnectionScopedCache extends BaseServiceCache
{
    public function __construct(private readonly ServiceConnection $serviceConnection) {}

    protected function connectionId(): ?int
    {
        return $this->serviceConnection->id;
    }

    /**
     * @return array{list: int, entity: int, metadata: int}
     */
    protected function ttls(): array
    {
        return config('mediamanager.cache.ttl');
    }
}
