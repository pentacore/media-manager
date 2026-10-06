<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrConnections;
use App\Services\Bazarr\BazarrClient;
use App\Services\Emby\EmbyClient;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Sonarr\SonarrClient;
use App\Services\Whisparr\WhisparrClient;

/**
 * A client for any connection type. Sonarr and Radarr come from
 * ArrConnections::client(), the one place those two are built.
 */
class ServiceClientFactory
{
    public function __construct(private readonly ArrConnections $arrConnections) {}

    public function make(ServiceConnection $serviceConnection): SonarrClient|RadarrClient|BazarrClient|EmbyClient|SeerrClient|ProwlarrClient|SabnzbdClient|WhisparrClient
    {
        return match ($serviceConnection->type) {
            ServiceType::Sonarr, ServiceType::Radarr => $this->arrConnections->client($serviceConnection),
            ServiceType::Bazarr => new BazarrClient($serviceConnection),
            ServiceType::Emby => new EmbyClient($serviceConnection),
            ServiceType::Seerr => new SeerrClient($serviceConnection),
            ServiceType::Prowlarr => new ProwlarrClient($serviceConnection),
            ServiceType::SABnzbd => new SabnzbdClient($serviceConnection),
            ServiceType::Whisparr => new WhisparrClient($serviceConnection),
        };
    }
}
