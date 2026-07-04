<?php

declare(strict_types=1);

namespace App\Cache\Services;

use App\Models\ServiceConnection;

class WhisparrCache extends BaseServiceCache
{
    public function __construct(private readonly ServiceConnection $serviceConnection) {}

    protected function service(): string
    {
        return 'whisparr';
    }

    protected function connectionId(): ?int
    {
        return $this->serviceConnection->id;
    }

    /**
     * @return array{list: int, entity: int, metadata: int}
     */
    protected function ttls(): array
    {
        return config('mediamanager.cache.ttl');
    }
}
