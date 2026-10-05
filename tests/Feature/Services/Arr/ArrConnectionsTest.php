<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrConnections;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;

test('the active client is built on the first active connection of the type', function (): void {
    ServiceConnection::factory()->sonarr()->inactive()->create();
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    expect(resolve(ArrConnections::class)->activeClient(ServiceType::Sonarr))->toBeInstanceOf(SonarrClient::class)
        ->and(resolve(ArrConnections::class)->activeClient(ServiceType::Radarr))->toBeInstanceOf(RadarrClient::class);
});

test('no active connection means no client', function (): void {
    ServiceConnection::factory()->radarr()->inactive()->create();

    expect(resolve(ArrConnections::class)->activeClient(ServiceType::Radarr))->toBeNull();
});

test('a service that is not Sonarr or Radarr is refused', function (): void {
    $emby = ServiceConnection::factory()->emby()->create();

    expect(fn (): mixed => resolve(ArrConnections::class)->activeClient(ServiceType::Emby))->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => resolve(ArrConnections::class)->client($emby))->toThrow(InvalidArgumentException::class);
});
