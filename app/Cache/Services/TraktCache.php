<?php

declare(strict_types=1);

namespace App\Cache\Services;

class TraktCache extends BaseServiceCache
{
    protected function service(): string
    {
        return 'trakt';
    }

    protected function connectionId(): ?int
    {
        return null;
    }

    /**
     * @return array{list: int, entity: int, metadata: int}
     */
    protected function ttls(): array
    {
        return config('mediamanager.cache.ttl');
    }
}
