<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum SabnzbdBulkAction: string
{
    use EnumUtils;

    case Pause = 'pause';
    case Resume = 'resume';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Pause => 'Pause',
            self::Resume => 'Resume',
            self::Delete => 'Delete',
        };
    }

    /** The verb the bulk summary toast uses for a handled slot. */
    public function pastTense(): string
    {
        return match ($this) {
            self::Pause => 'paused',
            self::Resume => 'resumed',
            self::Delete => 'deleted',
        };
    }
}
