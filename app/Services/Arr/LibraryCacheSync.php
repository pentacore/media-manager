<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Cache\Services\WhisparrCache;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * The library pages' Sync button: drops one Sonarr, Radarr or Whisparr
 * connection's cached reads (the whole connection scope, as a clearing
 * webhook does) so the next read goes upstream. Throttled per connection,
 * not per user: at most one bust every DECAY_SECONDS whoever asks, because
 * the button is open to every role that can see the page.
 */
final readonly class LibraryCacheSync
{
    public const int DECAY_SECONDS = 30;

    /**
     * Busts the connection's cache unless it was synced in the last
     * DECAY_SECONDS. Returns false, leaving the cache alone, when throttled.
     */
    public function sync(ServiceConnection $serviceConnection): bool
    {
        return RateLimiter::attempt(
            $this->key($serviceConnection),
            1,
            function () use ($serviceConnection): void {
                $this->cache($serviceConnection)->bustAll();
            },
            self::DECAY_SECONDS,
        ) !== false;
    }

    /**
     * Whole seconds until sync() busts again (at least 1 while throttled).
     */
    public function secondsUntilNextSync(ServiceConnection $serviceConnection): int
    {
        return max(1, RateLimiter::availableIn($this->key($serviceConnection)));
    }

    private function cache(ServiceConnection $serviceConnection): SonarrCache|RadarrCache|WhisparrCache
    {
        return match ($serviceConnection->type) {
            ServiceType::Sonarr => new SonarrCache($serviceConnection),
            ServiceType::Radarr => new RadarrCache($serviceConnection),
            ServiceType::Whisparr => new WhisparrCache($serviceConnection),
            default => throw new InvalidArgumentException(sprintf('Library sync does not cover %s connections.', $serviceConnection->type->label())),
        };
    }

    private function key(ServiceConnection $serviceConnection): string
    {
        return sprintf('library-sync:%d', $serviceConnection->id);
    }
}
