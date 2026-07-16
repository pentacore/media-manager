<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Events\ServiceConnectionUpserted;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('dispatches jobs on create when connection is active', function (): void {
    ServiceConnection::factory()->sonarr()->create(['is_active' => true]);

    Queue::assertPushed(PingServiceHealth::class, 1);
    Queue::assertPushed(FetchLatestServiceVersion::class, 1);
});

test('does not dispatch on create when connection is inactive', function (): void {
    ServiceConnection::factory()->sonarr()->inactive()->create();

    Queue::assertNotPushed(PingServiceHealth::class);
    Queue::assertNotPushed(FetchLatestServiceVersion::class);
});

test('dispatches jobs when url changes', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    Queue::fake();

    $connection->update(['url' => 'http://new-sonarr.local:8989']);

    Queue::assertPushed(PingServiceHealth::class, 1);
    Queue::assertPushed(FetchLatestServiceVersion::class, 1);
});

test('dispatches jobs when api_key changes', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    Queue::fake();

    $connection->update(['api_key' => 'new-key']);

    Queue::assertPushed(PingServiceHealth::class, 1);
});

test('dispatches jobs when type changes', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    Queue::fake();

    $connection->update(['type' => ServiceType::Radarr]);

    Queue::assertPushed(PingServiceHealth::class, 1);
});

test('dispatches jobs when connection is re-activated', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->inactive()->create();
    Queue::fake();

    $connection->update(['is_active' => true]);

    Queue::assertPushed(PingServiceHealth::class, 1);
    Queue::assertPushed(FetchLatestServiceVersion::class, 1);
});

test('does not dispatch on cosmetic updates', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    Queue::fake();

    $connection->update(['name' => 'Renamed']);

    Queue::assertNotPushed(PingServiceHealth::class);
    Queue::assertNotPushed(FetchLatestServiceVersion::class);
});

test('does not dispatch when deactivating', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    Queue::fake();

    $connection->update(['is_active' => false]);

    Queue::assertNotPushed(PingServiceHealth::class);
});

test('does not dispatch when an inactive connection is updated', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->inactive()->create();
    Queue::fake();

    $connection->update(['url' => 'http://new.local']);

    Queue::assertNotPushed(PingServiceHealth::class);
});

test('defers observer jobs and upsert events until the transaction commits', function (): void {
    Event::fake([ServiceConnectionUpserted::class]);

    $connection = DB::transaction(function (): ServiceConnection {
        $connection = ServiceConnection::factory()->sonarr()->create();

        Queue::assertNotPushed(PingServiceHealth::class);
        Queue::assertNotPushed(FetchLatestServiceVersion::class);
        Event::assertNotDispatched(ServiceConnectionUpserted::class);

        return $connection;
    });

    Queue::assertPushed(PingServiceHealth::class, 1);
    Queue::assertPushed(FetchLatestServiceVersion::class, 1);
    Event::assertDispatched(
        fn (ServiceConnectionUpserted $serviceConnectionUpserted): bool => $serviceConnectionUpserted->serviceConnection->is($connection),
    );
});

test('discards observer jobs and upsert events when the transaction rolls back', function (): void {
    Event::fake([ServiceConnectionUpserted::class]);

    try {
        DB::transaction(function (): never {
            ServiceConnection::factory()->sonarr()->create();

            Queue::assertNotPushed(PingServiceHealth::class);
            Queue::assertNotPushed(FetchLatestServiceVersion::class);
            Event::assertNotDispatched(ServiceConnectionUpserted::class);

            throw new RuntimeException('Roll back observer transaction.');
        });
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('Roll back observer transaction.');
    }

    Queue::assertNotPushed(PingServiceHealth::class);
    Queue::assertNotPushed(FetchLatestServiceVersion::class);
    Event::assertNotDispatched(ServiceConnectionUpserted::class);
});
