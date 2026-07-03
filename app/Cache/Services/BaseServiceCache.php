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
 * Each cached entry is paired with a "warm sentinel" — a small companion key
 * stored under the same tag scope whose TTL expires `WARM_THRESHOLD_SECONDS`
 * before the data does. The cache warmer (`services:warm-caches`) consults
 * the sentinel to decide whether a value is near expiry: sentinel alive ⇒
 * still fresh, skip; sentinel missing ⇒ refresh atomically and reset the
 * sentinel. This avoids reading raw Redis TTLs through the tagged-key
 * indirection while keeping `bustAll()` semantics intact (the sentinel is
 * busted alongside the data).
 *
 * NOTE: the cache store is resolved on every call via `Cache::store(config('mediamanager.cache.store'))`.
 * Tests that swap the configured store mid-run must flush whatever store
 * they previously used — there is no per-instance store memoization.
 */
abstract class BaseServiceCache
{
    /**
     * Sentinel expires this many seconds before data, signalling "due for refresh".
     */
    public const int WARM_THRESHOLD_SECONDS = 90;

    abstract protected function service(): string;

    abstract protected function connectionId(): ?int;

    /**
     * @return array{list: int, entity: int, metadata: int}
     */
    abstract protected function ttls(): array;

    public function rememberList(string $suffix, Closure $miss): mixed
    {
        return $this->rememberWithSentinel($suffix, $this->ttls()['list'], $miss);
    }

    public function rememberEntity(string $suffix, Closure $miss): mixed
    {
        return $this->rememberWithSentinel($suffix, $this->ttls()['entity'], $miss);
    }

    public function rememberMetadata(string $suffix, Closure $miss): mixed
    {
        return $this->rememberWithSentinel($suffix, $this->ttls()['metadata'], $miss);
    }

    public function warmList(string $suffix, Closure $fetch): void
    {
        $this->refreshIfStale($suffix, $this->ttls()['list'], $fetch);
    }

    public function warmEntity(string $suffix, Closure $fetch): void
    {
        $this->refreshIfStale($suffix, $this->ttls()['entity'], $fetch);
    }

    public function warmMetadata(string $suffix, Closure $fetch): void
    {
        $this->refreshIfStale($suffix, $this->ttls()['metadata'], $fetch);
    }

    /**
     * Write a value into the entity bucket and refresh its sentinel. Used by
     * batch fetchers that already have the value in hand and want to make
     * future single-entity reads cache-hit without a second upstream call.
     */
    public function putEntity(string $suffix, mixed $value): void
    {
        $ttl = $this->ttls()['entity'];
        $repository = $this->scopedStore();
        $repository->put($this->key($suffix), $value, $ttl);
        $repository->put($this->sentinelKey($suffix), 1, $this->sentinelTtl($ttl));
    }

    /**
     * Read-only fetch from the entity bucket. Returns null when the slot is
     * empty — does NOT populate the cache. Use this from batch fetchers
     * that need to peek at what's already cached without triggering a miss.
     */
    public function getEntity(string $suffix): mixed
    {
        return $this->scopedStore()->get($this->key($suffix));
    }

    public function bustAll(): void
    {
        $this->scopedStore()->flush();
    }

    private function rememberWithSentinel(string $suffix, int $ttl, Closure $miss): mixed
    {
        $repository = $this->scopedStore();

        return $repository->remember($this->key($suffix), $ttl, function () use ($repository, $suffix, $ttl, $miss): mixed {
            $value = $miss();
            $repository->put($this->sentinelKey($suffix), 1, $this->sentinelTtl($ttl));

            return $value;
        });
    }

    private function refreshIfStale(string $suffix, int $ttl, Closure $fetch): void
    {
        $repository = $this->scopedStore();

        if ($repository->has($this->sentinelKey($suffix))) {
            return;
        }

        $lock = Cache::lock($this->lockKey($suffix), 30);

        if (! $lock->get()) {
            return;
        }

        try {
            $value = $fetch();
            $repository->put($this->key($suffix), $value, $ttl);
            $repository->put($this->sentinelKey($suffix), 1, $this->sentinelTtl($ttl));
        } finally {
            $lock->release();
        }
    }

    protected function scopedStore(): Repository
    {
        return Cache::store((string) config('mediamanager.cache.store', 'redis'))
            ->tags($this->tags());
    }

    /**
     * @return array<int, string>
     */
    protected function tags(): array
    {
        if ($this->connectionId() !== null) {
            return [sprintf('%s:%d', $this->service(), $this->connectionId())];
        }

        return [$this->service()];
    }

    protected function key(string $suffix): string
    {
        $prefix = $this->connectionId() !== null
            ? sprintf('%s:%d', $this->service(), $this->connectionId())
            : $this->service();

        return $prefix.':'.$suffix;
    }

    protected function sentinelKey(string $suffix): string
    {
        return $this->key('warm-sentinel:'.$suffix);
    }

    private function lockKey(string $suffix): string
    {
        return 'warm-lock:'.$this->key($suffix);
    }

    private function sentinelTtl(int $bucketTtl): int
    {
        return max(1, $bucketTtl - self::WARM_THRESHOLD_SECONDS);
    }
}
