<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum ServiceType: string
{
    use EnumUtils;

    case Sonarr = 'sonarr';
    case Radarr = 'radarr';
    case Emby = 'emby';
    case Jellyseerr = 'jellyseerr';

    public function label(): string
    {
        return match ($this) {
            self::Sonarr => 'Sonarr',
            self::Radarr => 'Radarr',
            self::Emby => 'Emby',
            self::Jellyseerr => 'Jellyseerr',
        };
    }
}
