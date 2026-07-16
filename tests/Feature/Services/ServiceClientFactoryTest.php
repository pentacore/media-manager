<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use App\Services\Emby\EmbyClient;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\Seerr\SeerrClient;
use App\Services\ServiceClientFactory;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('make returns the right client per service type', function (ServiceType $serviceType, string $expectedClass): void {
    $serviceConnection = ServiceConnection::factory()->create(['type' => $serviceType]);

    $client = resolve(ServiceClientFactory::class)->make($serviceConnection);

    expect($client)->toBeInstanceOf($expectedClass);
})->with([
    [ServiceType::Sonarr, SonarrClient::class],
    [ServiceType::Radarr, RadarrClient::class],
    [ServiceType::Bazarr, BazarrClient::class],
    [ServiceType::Emby, EmbyClient::class],
    [ServiceType::Seerr, SeerrClient::class],
    [ServiceType::SABnzbd, SabnzbdClient::class],
]);

test('makeForType resolves the active connection then makes a client', function (): void {
    ServiceConnection::factory()->sonarr()->create(['is_active' => true]);

    $client = resolve(ServiceClientFactory::class)->makeForType(ServiceType::Sonarr);

    expect($client)->toBeInstanceOf(SonarrClient::class);
});

test('makeForType resolves an active Bazarr connection', function (): void {
    ServiceConnection::factory()->bazarr()->create(['is_active' => true]);

    $client = resolve(ServiceClientFactory::class)->makeForType(ServiceType::Bazarr);

    expect($client)->toBeInstanceOf(BazarrClient::class);
});

test('makeForType throws when no active connection exists', function (): void {
    expect(fn () => resolve(ServiceClientFactory::class)->makeForType(ServiceType::Sonarr))
        ->toThrow(ModelNotFoundException::class);
});

test('factory makes ProwlarrClient for Prowlarr connection', function (): void {
    $connection = ServiceConnection::factory()->prowlarr()->create();

    $client = resolve(ServiceClientFactory::class)->make($connection);

    expect($client)->toBeInstanceOf(ProwlarrClient::class);
});
