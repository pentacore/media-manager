<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ServiceCheckBatch
{
    public const string CACHE_KEY_HEALTH = 'service-checks:last-batch:health';

    public const string CACHE_KEY_VERSIONS = 'service-checks:last-batch:versions';

    /**
     * Dispatch a parallel health-check batch for the given active connections.
     * Caches the resulting batch id under CACHE_KEY_HEALTH and triggers a
     * single dashboard-stats broadcast on completion.
     *
     * @param  Collection<int, ServiceConnection>  $connections
     */
    public static function dispatchHealth(Collection $connections): Batch
    {
        $jobs = $connections->map(static fn (ServiceConnection $serviceConnection): PingServiceHealth => new PingServiceHealth($serviceConnection))->all();

        $batch = Bus::batch($jobs)
            ->name('service-health')
            ->then(static function (Batch $batch): void {
                try {
                    resolve(DashboardStatsService::class)->broadcast();
                } catch (Throwable) {
                    // Stats broadcast is opportunistic; failures here must not
                    // poison the batch's success state.
                }
            })
            ->dispatch();

        Cache::put(self::CACHE_KEY_HEALTH, $batch->id, now()->addDay());

        return $batch;
    }

    /**
     * Dispatch a parallel version-check batch for the subset of connections
     * whose service type has a known upstream repo. Caches the batch id under
     * CACHE_KEY_VERSIONS.
     *
     * @param  Collection<int, ServiceConnection>  $connections
     */
    public static function dispatchVersions(Collection $connections): Batch
    {
        $jobs = $connections
            ->filter(static fn (ServiceConnection $serviceConnection): bool => array_key_exists(
                (string) $serviceConnection->type->value,
                FetchLatestServiceVersion::REPO_MAP,
            ))
            ->map(static fn (ServiceConnection $serviceConnection): FetchLatestServiceVersion => new FetchLatestServiceVersion($serviceConnection))
            ->values()
            ->all();

        $batch = Bus::batch($jobs)
            ->name('service-versions')
            ->dispatch();

        Cache::put(self::CACHE_KEY_VERSIONS, $batch->id, now()->addDay());

        return $batch;
    }
}
