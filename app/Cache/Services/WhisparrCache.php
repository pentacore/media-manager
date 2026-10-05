<?php

declare(strict_types=1);

namespace App\Cache\Services;

class WhisparrCache extends ConnectionScopedCache
{
    protected function service(): string
    {
        return 'whisparr';
    }
}
