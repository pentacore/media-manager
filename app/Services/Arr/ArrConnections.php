<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use InvalidArgumentException;

/**
 * The single Sonarr/Radarr client builder: library pages, sidebar badges,
 * executors (ArrLibraryActions and its subclasses), AI tools, and
 * ServiceClientFactory (which delegates Sonarr/Radarr here) all go through
 * `client()` instead of a local `new SonarrClient`/`new RadarrClient`
 * ternary — see app.md. "Active" is the primary connection — the first
 * active one by id, exactly as ServiceConnection::findActive() picks it —
 * so every surface reads the same instance and only its items get library
 * links.
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
            ServiceType::Sonarr => new SonarrClient($serviceConnection),
            ServiceType::Radarr => new RadarrClient($serviceConnection),
            default => throw new InvalidArgumentException(sprintf('%s is not a Sonarr or Radarr service.', $serviceConnection->type->label())),
        };
    }
}
