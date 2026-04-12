<?php

declare(strict_types=1);

namespace App\Enums;

enum ServiceType: string
{
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
