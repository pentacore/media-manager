<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use App\Services\Emby\EmbyClient;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Sonarr\SonarrClient;
use App\Services\Whisparr\WhisparrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ServiceClientFactory
{
    public function make(ServiceConnection $serviceConnection): SonarrClient|RadarrClient|BazarrClient|EmbyClient|SeerrClient|ProwlarrClient|SabnzbdClient|WhisparrClient
    {
        return match ($serviceConnection->type) {
            ServiceType::Sonarr => new SonarrClient($serviceConnection),
            ServiceType::Radarr => new RadarrClient($serviceConnection),
            ServiceType::Bazarr => new BazarrClient($serviceConnection),
            ServiceType::Emby => new EmbyClient($serviceConnection),
            ServiceType::Seerr => new SeerrClient($serviceConnection),
            ServiceType::Prowlarr => new ProwlarrClient($serviceConnection),
            ServiceType::SABnzbd => new SabnzbdClient($serviceConnection),
            ServiceType::Whisparr => new WhisparrClient($serviceConnection),
        };
    }

    /**
     * Build a client by resolving the active connection for the given type.
     *
     * @throws ModelNotFoundException when no active connection exists
     */
    public function makeForType(ServiceType $serviceType): SonarrClient|RadarrClient|BazarrClient|EmbyClient|SeerrClient|ProwlarrClient|SabnzbdClient|WhisparrClient
    {
        return $this->make(ServiceConnection::resolveActive($serviceType));
    }
}
