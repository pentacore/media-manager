<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('an active pinned connection is returned', function (): void {
    ServiceConnection::factory()->sonarr()->create();
    $pinned = ServiceConnection::factory()->sonarr()->create();

    expect(ServiceConnection::resolvePinned(['service_connection_id' => $pinned->id], ServiceType::Sonarr)->id)->toBe($pinned->id);
});

test('a deactivated pinned connection aborts instead of falling back', function (): void {
    ServiceConnection::factory()->sonarr()->create();
    $pinned = ServiceConnection::factory()->sonarr()->inactive()->create();

    expect(fn (): ServiceConnection => ServiceConnection::resolvePinned(['service_connection_id' => $pinned->id], ServiceType::Sonarr))
        ->toThrow(ModelNotFoundException::class, 'deactivated');
});

test('a pinned connection of another type falls back to the active connection', function (): void {
    $emby = ServiceConnection::factory()->emby()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->inactive()->create();

    expect(ServiceConnection::resolvePinned(['service_connection_id' => $sonarr->id], ServiceType::Emby)->id)->toBe($emby->id);
});

test('a deleted pinned connection aborts with a message that names the pin', function (string $resolver): void {
    ServiceConnection::factory()->sonarr()->create();

    expect(fn (): ServiceConnection => ServiceConnection::{$resolver}(['service_connection_id' => 999_999], ServiceType::Sonarr))
        ->toThrow(ModelNotFoundException::class, 'Service connection 999999 pinned to this action no longer exists; aborting instead of acting on a different instance.');
})->with(['resolvePinned', 'resolvePinnedStrict']);
