<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum ActionQueueBulkAction: string
{
    use EnumUtils;

    case Approve = 'approve';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approve',
            self::Reject => 'Reject',
        };
    }

    /** The verb the bulk summary toast uses for a reviewed request. */
    public function pastTense(): string
    {
        return match ($this) {
            self::Approve => 'approved',
            self::Reject => 'rejected',
        };
    }
}
