<?php

declare(strict_types=1);

namespace App\Cache\Services;

class RadarrCache extends ConnectionScopedCache
{
    protected function service(): string
    {
        return 'radarr';
    }
}
