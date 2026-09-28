<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Makes GET /up (the web container's healthcheck) fail when the app cannot
 * reach Postgres or a Valkey connection it is configured to use. Upstream
 * media services are deliberately not checked: a down Sonarr must not mark
 * the dashboard unhealthy. Throwing is the contract — the framework's health
 * route reports the exception and answers 500.
 */
final class CheckInfrastructureHealth
{
    public function handle(DiagnosingHealth $diagnosingHealth): void
    {
        DB::connection()->select('select 1');

        foreach ($this->redisConnections() as $connection) {
            Redis::connection($connection)->ping();
        }
    }

    /**
     * The redis connections behind the default cache store and queue
     * connection, when those use redis.
     *
     * @return list<string>
     */
    private function redisConnections(): array
    {
        $cacheStore = (string) config('cache.default');
        $queueConnection = (string) config('queue.default');
        $connections = [];

        if (config(sprintf('cache.stores.%s.driver', $cacheStore)) === 'redis') {
            $connections[] = (string) config(sprintf('cache.stores.%s.connection', $cacheStore), 'default');
        }

        if (config(sprintf('queue.connections.%s.driver', $queueConnection)) === 'redis') {
            $connections[] = (string) config(sprintf('queue.connections.%s.connection', $queueConnection), 'default');
        }

        return array_values(array_unique($connections));
    }
}
