<?php

declare(strict_types=1);

namespace App\Cache\Services;

class ProwlarrCache extends ConnectionScopedCache
{
    protected function service(): string
    {
        return 'prowlarr';
    }
}
