<?php

declare(strict_types=1);

namespace App\Cache\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Base for per-service tagged caches (Sonarr, Radarr, Seerr, Prowlarr, Tmdb, Trakt).
 *
 * Subclasses provide the service slug, an optional connection id (null for
 * household-keyed services like Tmdb/Trakt), and the TTL bucket map. Cache
 * entries are tagged with `{service}:{id}` (or `{service}` when no
 * connection) so `bustAll()` only flushes entries for this scope.
 *
 * NOTE: the cache store is resolved on every call via `Cache::store(config('mediamanager.cache.store'))`.
 * Tests that swap the configured store mid-run must flush whatever store
 * they previously used — there is no per-instance store memoization.
 */
abstract class BaseServiceCache
{
    abstract protected function service(): string;

    abstract protected function connectionId(): ?int;

    /**
     * @return array{list: int, entity: int, metadata: int}
     */
    abstract protected function ttls(): array;

    public function rememberList(string $suffix, Closure $miss): mixed
    {
        return $this->scopedStore()->remember($this->key($suffix), $this->ttls()['list'], $miss);
    }

    public function rememberEntity(string $suffix, Closure $miss): mixed
    {
        return $this->scopedStore()->remember($this->key($suffix), $this->ttls()['entity'], $miss);
    }

    public function rememberMetadata(string $suffix, Closure $miss): mixed
    {
        return $this->scopedStore()->remember($this->key($suffix), $this->ttls()['metadata'], $miss);
    }

    public function bustAll(): void
    {
        $this->scopedStore()->flush();
    }

    private function scopedStore(): Repository
    {
        return Cache::store((string) config('mediamanager.cache.store', 'redis'))
            ->tags($this->tags());
    }

    /**
     * @return array<int, string>
     */
    private function tags(): array
    {
        if ($this->connectionId() !== null) {
            return [sprintf('%s:%d', $this->service(), $this->connectionId())];
        }

        return [$this->service()];
    }

    private function key(string $suffix): string
    {
        $prefix = $this->connectionId() !== null
            ? sprintf('%s:%d', $this->service(), $this->connectionId())
            : $this->service();

        return $prefix.':'.$suffix;
    }
}
