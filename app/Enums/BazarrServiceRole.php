<?php

declare(strict_types=1);

namespace App\Enums;

enum BazarrServiceRole: string
{
    case Sonarr = 'sonarr';
    case Radarr = 'radarr';

    public function serviceType(): ServiceType
    {
        return match ($this) {
            self::Sonarr => ServiceType::Sonarr,
            self::Radarr => ServiceType::Radarr,
        };
    }
}
