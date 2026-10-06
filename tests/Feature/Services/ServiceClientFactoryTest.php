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

test('factory makes ProwlarrClient for Prowlarr connection', function (): void {
    $connection = ServiceConnection::factory()->prowlarr()->create();

    $client = resolve(ServiceClientFactory::class)->make($connection);

    expect($client)->toBeInstanceOf(ProwlarrClient::class);
});
