<?php

declare(strict_types=1);

namespace App\Cache\Services;

class SeerrCache extends ConnectionScopedCache
{
    protected function service(): string
    {
        return 'seerr';
    }
}
