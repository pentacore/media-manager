<?php

declare(strict_types=1);

namespace App\Cache\Services;

use App\Models\ServiceConnection;

class RadarrCache extends BaseServiceCache
{
    public function __construct(private ServiceConnection $connection) {}

    protected function service(): string
    {
        return 'radarr';
    }

    protected function connectionId(): ?int
    {
        return $this->connection->id;
    }

    /**
     * @return array{list: int, entity: int, metadata: int}
     */
    protected function ttls(): array
    {
        return config('mediamanager.cache.ttl');
    }
}
