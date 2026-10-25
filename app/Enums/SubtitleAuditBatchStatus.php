<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum SubtitleAuditBatchStatus: string
{
    use EnumUtils;

    case Searching = 'searching';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Searching => 'Searching',
            self::Ready => 'Ready',
        };
    }
}
