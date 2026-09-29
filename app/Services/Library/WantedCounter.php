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
 * Monitored missing episodes + movies for the Wanted sidebar badge. Cached
 * like InterventionCounter so HandleInertiaRequests stays cheap: recomputed
 * inline only on a cold cache, never cached empty-handed on an outage.
 */
final class WantedCounter
{
    public const string CACHE_KEY = 'library:wanted-missing-count';

    public const int CACHE_TTL = 600;

    public const int FAILURE_CACHE_TTL = 60;

    public function get(): int
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_int($value) ? $value : 0;
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
                $count += (int) ($client->getWanted('missing', 1, 1, true)['totalRecords'] ?? 0);
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
