<?php

declare(strict_types=1);

namespace App\Support\Cache;

interface Warmable
{
    public function warm(): void;
}
