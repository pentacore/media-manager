<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Monitored missing episodes + movies for the Wanted sidebar badge.
 *
 * The scheduled `library:refresh-wanted-count` command (every five minutes)
 * keeps the cache warm; on a failing service it keeps the previous total
 * instead of shrinking the badge. HandleInertiaRequests only calls warm() on a
 * cold cache: one request at a time (a short cache lock — the rest get the
 * cached value or 0) makes a single non-retrying call per service, and a
 * failure with nothing cached writes a FAILURE_CACHE_TTL entry so page loads
 * never keep waiting on an unreachable upstream.
 */
final class WantedCounter
{
    public const string CACHE_KEY = 'library:wanted-missing-count';

    public const int CACHE_TTL = 600;

    public const int FAILURE_CACHE_TTL = 60;

    public const string RECOMPUTE_LOCK_KEY = 'library:wanted-missing-count:recompute';

    public const int RECOMPUTE_LOCK_SECONDS = 30;

    public function get(): int
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_int($value) ? $value : 0;
    }

    /**
     * The cold-cache recompute for page loads: when another request already
     * holds the lock, return whatever is cached (0 when nothing is) rather
     * than walking the upstreams concurrently.
     */
    public function warm(): int
    {
        $lock = Cache::lock(self::RECOMPUTE_LOCK_KEY, self::RECOMPUTE_LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->get();
        }

        try {
            return $this->recompute();
        } finally {
            $lock->release();
        }
    }

    public function recompute(): int
    {
        $count = 0;
        $anyFailed = false;

        foreach ([ServiceType::Sonarr, ServiceType::Radarr] as $serviceType) {
            try {
                $connection = ServiceConnection::resolveActive($serviceType);
            } catch (ModelNotFoundException) {
                continue;
            }

            $client = $serviceType === ServiceType::Sonarr ? new SonarrClient($connection) : new RadarrClient($connection);

            try {
                $count += (int) ($client->getWanted('missing', 1, 1, true, withRetry: false)['totalRecords'] ?? 0);
            } catch (RequestException|ConnectionException) {
                $anyFailed = true;
            }
        }

        if ($anyFailed) {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_int($cached)) {
                return $cached;
            }

            Cache::put(self::CACHE_KEY, $count, self::FAILURE_CACHE_TTL);

            return $count;
        }

        Cache::put(self::CACHE_KEY, $count, self::CACHE_TTL);

        return $count;
    }
}
