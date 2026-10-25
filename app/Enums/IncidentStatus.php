<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum IncidentStatus: string
{
    use EnumUtils;

    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
        };
    }
}
