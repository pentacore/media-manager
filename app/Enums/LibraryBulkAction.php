<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * The bulk actions of the Sonarr, Radarr and Whisparr library pages. Each one
 * maps onto one LibraryActionRequester method per title.
 */
enum LibraryBulkAction: string
{
    use EnumUtils;

    case Monitor = 'monitor';
    case Unmonitor = 'unmonitor';
    case QualityProfile = 'quality_profile';
    case Search = 'search';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Monitor => 'Monitor',
            self::Unmonitor => 'Unmonitor',
            self::QualityProfile => 'Change quality profile',
            self::Search => 'Search',
            self::Delete => 'Delete',
        };
    }
}
