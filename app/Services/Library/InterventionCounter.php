<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\ServiceType;
use App\Events\LibraryInterventionChanged;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Counts Sonarr + Radarr download-queue rows that need an admin to step
 * in (warning / error tracked status, or one of the import* "blocked"
 * tracked states). The result is cached so the sidebar badge — which
 * Inertia ships on every request — doesn't trigger two upstream HTTP
 * calls per page navigation. Recompute is triggered explicitly on
 * webhook arrival and from a 5-minute scheduled poll backstop.
 */
class InterventionCounter
{
    public const string CACHE_KEY = 'library:intervention-count';

    /** Cache TTL (seconds). Sized to be longer than the scheduled refresh
     *  cadence so the cache is always populated by the next poll. */
    public const int CACHE_TTL = 600;

    /** Tracked-state values that mean "stuck — admin needs to act". */
    private const array INTERVENTION_STATES = [
        'importBlocked',
        'importPending',
        'importFailed',
        'failedPending',
    ];

    public function get(): int
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_int($value) ? $value : 0;
    }

    /**
     * Re-fetch from Sonarr + Radarr, store the new count, and broadcast
     * it. Returns the count so callers (e.g. webhook handlers) can react
     * inline if they want.
     */
    public function recompute(): int
    {
        $count = 0;
        $anyFailed = false;

        foreach ([ServiceType::Sonarr, ServiceType::Radarr] as $type) {
            $client = $this->resolveClient($type);
            if (! $client instanceof ArrClient) {
                continue;
            }

            $serviceCount = $this->countForClient($client);

            if ($serviceCount === null) {
                $anyFailed = true;

                continue;
            }

            $count += $serviceCount;
        }

        // A flaky upstream must not zero out the badge: when any service
        // failed to answer, keep the last cached total instead of
        // overwriting it with a partial (undercounted) sum.
        if ($anyFailed) {
            $cached = Cache::get(self::CACHE_KEY);

            return is_int($cached) ? $cached : $count;
        }

        Cache::put(self::CACHE_KEY, $count, self::CACHE_TTL);
        event(new LibraryInterventionChanged($count));

        return $count;
    }

    private function resolveClient(ServiceType $serviceType): ?ArrClient
    {
        try {
            $connection = ServiceConnection::resolveActive($serviceType);
        } catch (ModelNotFoundException) {
            return null;
        }

        return $serviceType === ServiceType::Sonarr
            ? new SonarrClient($connection)
            : new RadarrClient($connection);
    }

    /**
     * Null = upstream didn't answer (the caller preserves the cached total
     * rather than recording a partial sum).
     */
    private function countForClient(ArrClient $arrClient): ?int
    {
        try {
            $payload = $arrClient->getQueue([
                'page' => 1,
                'pageSize' => 200,
                'includeUnknownSeriesItems' => 'true',
                'includeUnknownMovieItems' => 'true',
            ]);
        } catch (RequestException|ConnectionException) {
            return null;
        }

        $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];

        $matched = 0;
        foreach ($records as $record) {
            if ($this->needsIntervention($record)) {
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function needsIntervention(array $record): bool
    {
        $status = $record['trackedDownloadStatus'] ?? null;
        $state = $record['trackedDownloadState'] ?? null;

        if ($status === 'error' || $status === 'warning') {
            return true;
        }

        return in_array($state, self::INTERVENTION_STATES, true);
    }
}
