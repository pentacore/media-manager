<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum HealthStatus: string
{
    use EnumUtils;

    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Unhealthy => 'Unhealthy',
            self::Unknown => 'Unknown',
        };
    }
}
