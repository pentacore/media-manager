<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use InvalidArgumentException;

/**
 * The single Sonarr/Radarr client builder: every Sonarr/Radarr client in the
 * app — library pages, sidebar badges, executors (ArrLibraryActions and its
 * subclasses), AI tools, jobs and console commands, and ServiceClientFactory
 * (which delegates Sonarr/Radarr here) — is built here through `client()`,
 * `sonarr()`, or `radarr()`, never a local `new SonarrClient`/`new
 * RadarrClient` — see app.md. `tests/Unit/Architecture/ArrClientConstructionArchTest.php`
 * fails on a `new SonarrClient`/`new RadarrClient` anywhere else under
 * `app/`. "Active" is the primary connection — the first active one by id,
 * exactly as ServiceConnection::findActive() picks it — so every surface
 * reads the same instance and only its items get library links.
 */
final readonly class ArrConnections
{
    /**
     * The primary connection's client, or null when none is active.
     *
     * @throws InvalidArgumentException for a service that is not Sonarr or Radarr
     */
    public function activeClient(ServiceType $serviceType): SonarrClient|RadarrClient|null
    {
        throw_unless(
            in_array($serviceType, [ServiceType::Sonarr, ServiceType::Radarr], true),
            InvalidArgumentException::class,
            sprintf('%s is not a Sonarr or Radarr service.', $serviceType->label()),
        );

        $serviceConnection = ServiceConnection::findActive($serviceType);

        return $serviceConnection instanceof ServiceConnection ? $this->client($serviceConnection) : null;
    }

    /**
     * @throws InvalidArgumentException for a connection that is not Sonarr or Radarr
     */
    public function client(ServiceConnection $serviceConnection): SonarrClient|RadarrClient
    {
        return match ($serviceConnection->type) {
            ServiceType::Sonarr => $this->sonarr($serviceConnection),
            ServiceType::Radarr => $this->radarr($serviceConnection),
            default => throw new InvalidArgumentException(sprintf('%s is not a Sonarr or Radarr service.', $serviceConnection->type->label())),
        };
    }

    /**
     * @throws InvalidArgumentException for a connection that is not Sonarr
     */
    public function sonarr(ServiceConnection $serviceConnection): SonarrClient
    {
        throw_unless($serviceConnection->type === ServiceType::Sonarr, InvalidArgumentException::class, sprintf('%s is not a Sonarr service.', $serviceConnection->type->label()));

        return new SonarrClient($serviceConnection);
    }

    /**
     * @throws InvalidArgumentException for a connection that is not Radarr
     */
    public function radarr(ServiceConnection $serviceConnection): RadarrClient
    {
        throw_unless($serviceConnection->type === ServiceType::Radarr, InvalidArgumentException::class, sprintf('%s is not a Radarr service.', $serviceConnection->type->label()));

        return new RadarrClient($serviceConnection);
    }
}
