<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum ServiceType: string
{
    use EnumUtils;

    case Sonarr = 'sonarr';
    case Radarr = 'radarr';
    case Bazarr = 'bazarr';
    case Emby = 'emby';
    case Seerr = 'seerr';
    case Prowlarr = 'prowlarr';
    case SABnzbd = 'sabnzbd';
    case Whisparr = 'whisparr';

    public function label(): string
    {
        return match ($this) {
            self::Sonarr => 'Sonarr',
            self::Radarr => 'Radarr',
            self::Bazarr => 'Bazarr',
            self::Emby => 'Emby',
            self::Seerr => 'Seerr',
            self::Prowlarr => 'Prowlarr',
            self::SABnzbd => 'SABnzbd',
            self::Whisparr => 'Whisparr',
        };
    }

    public function supportsWebhookConfiguration(): bool
    {
        return match ($this) {
            self::Sonarr, self::Radarr, self::Prowlarr, self::Whisparr => true,
            default => false,
        };
    }
}
