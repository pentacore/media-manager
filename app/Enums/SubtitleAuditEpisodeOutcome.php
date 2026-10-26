<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum SubtitleAuditEpisodeOutcome: string
{
    use EnumUtils;

    case Searching = 'searching';
    case Queued = 'queued';
    case ManualPick = 'manual_pick';
    case NothingFound = 'nothing_found';
    case NoLongerMissing = 'no_longer_missing';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Searching => 'Searching',
            self::Queued => 'Queued',
            self::ManualPick => 'Manual pick',
            self::NothingFound => 'Nothing found',
            self::NoLongerMissing => 'No longer missing',
            self::Failed => 'Failed',
        };
    }
}
