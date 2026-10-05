<?php

declare(strict_types=1);

namespace App\Cache\Services;

class SabnzbdCache extends ConnectionScopedCache
{
    protected function service(): string
    {
        return 'sabnzbd';
    }
}
