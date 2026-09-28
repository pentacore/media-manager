<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

test('the health endpoint answers 200 when the database is reachable and no Valkey connection is in use', function (): void {
    Redis::shouldReceive('connection')->never();

    $this->get('/up')->assertOk();
});

test('the health endpoint pings the Valkey connections the cache and queue use', function (): void {
    config()->set('cache.default', 'redis');
    config()->set('queue.default', 'redis');
    $connection = Mockery::mock();
    $connection->shouldReceive('ping')->twice()->andReturn(true);
    Redis::shouldReceive('connection')->with('cache')->once()->andReturn($connection);
    Redis::shouldReceive('connection')->with('default')->once()->andReturn($connection);

    $this->get('/up')->assertOk();
});

test('the health endpoint answers 500 when Valkey is unreachable', function (): void {
    config()->set('cache.default', 'redis');
    Redis::shouldReceive('connection')->with('cache')->andThrow(new RuntimeException('Connection refused'));

    $this->get('/up')->assertInternalServerError();
});

test('the health endpoint answers 500 when the database is unreachable', function (): void {
    $originalDefaultConnection = config('database.default');
    config()->set('database.connections.unreachable', [
        ...config('database.connections.pgsql'),
        'host' => '127.0.0.1',
        'port' => 1,
    ]);
    config()->set('database.default', 'unreachable');

    $this->get('/up')->assertInternalServerError();

    // RefreshDatabase's teardown re-reads database.default to roll back its
    // transaction; leaving it pointed at the unreachable connection would
    // make teardown itself try (and fail) to connect.
    config()->set('database.default', $originalDefaultConnection);
});
