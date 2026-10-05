<?php

declare(strict_types=1);

namespace App\Cache\Services;

class SonarrCache extends ConnectionScopedCache
{
    protected function service(): string
    {
        return 'sonarr';
    }
}
