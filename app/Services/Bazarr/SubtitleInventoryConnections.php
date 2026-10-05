<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\ServiceClientFactory;
use InvalidArgumentException;

/**
 * The Bazarr client a subtitle inventory read uses, and the Sonarr/Radarr
 * connection a Bazarr connection is mapped to when that mapping is usable
 * (present, active and of the role's service type).
 */
final readonly class SubtitleInventoryConnections
{
    public function __construct(
        private ServiceClientFactory $serviceClientFactory,
    ) {}

    public function bazarrClient(ServiceConnection $serviceConnection): BazarrClient
    {
        throw_unless($serviceConnection->type === ServiceType::Bazarr, InvalidArgumentException::class, 'Subtitle inventory requires a Bazarr connection.');

        $client = $this->serviceClientFactory->make($serviceConnection);

        throw_unless($client instanceof BazarrClient, InvalidArgumentException::class, 'Subtitle inventory requires a Bazarr client.');

        return $client;
    }

    public function activeMapped(
        ServiceConnection $serviceConnection,
        BazarrServiceRole $bazarrServiceRole,
    ): ?ServiceConnection {
        $connection = $serviceConnection->mappedConnection($bazarrServiceRole);

        if (! $connection instanceof ServiceConnection
            || ! $connection->is_active
            || $connection->type !== $bazarrServiceRole->serviceType()) {
            return null;
        }

        return $connection;
    }
}
