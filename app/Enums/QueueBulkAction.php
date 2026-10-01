<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum QueueBulkAction: string
{
    use EnumUtils;

    case Remove = 'remove';
    case Blocklist = 'blocklist';

    public function label(): string
    {
        return match ($this) {
            self::Remove => 'Remove from queue',
            self::Blocklist => 'Blocklist and search again',
        };
    }

    /** The verb the bulk summary toast uses for a handled queue item. */
    public function pastTense(): string
    {
        return match ($this) {
            self::Remove => 'removed',
            self::Blocklist => 'blocklisted',
        };
    }
}
