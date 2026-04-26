<?php

declare(strict_types=1);

use App\Events\ServiceConnectionDeleted;
use App\Events\ServiceConnectionUpserted;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('creating a connection broadcasts ServiceConnectionUpserted', function (): void {
    Event::fake([ServiceConnectionUpserted::class]);

    $connection = ServiceConnection::factory()->sonarr()->create();

    Event::assertDispatched(
        ServiceConnectionUpserted::class,
        fn (ServiceConnectionUpserted $event): bool => $event->serviceConnection->is($connection),
    );
});

test('updating a connection broadcasts ServiceConnectionUpserted', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();

    Event::fake([ServiceConnectionUpserted::class]);

    $connection->update(['name' => 'Renamed Sonarr']);

    Event::assertDispatched(
        ServiceConnectionUpserted::class,
        fn (ServiceConnectionUpserted $event): bool => $event->serviceConnection->name === 'Renamed Sonarr',
    );
});

test('deleting a connection broadcasts ServiceConnectionDeleted with id', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $id = $connection->id;

    Event::fake([ServiceConnectionDeleted::class]);

    $connection->delete();

    Event::assertDispatched(
        ServiceConnectionDeleted::class,
        fn (ServiceConnectionDeleted $event): bool => $event->serviceConnectionId === $id,
    );
});
