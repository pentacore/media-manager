<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum HostCheckStatus: string
{
    use EnumUtils;

    case Ok = 'ok';
    case Warn = 'warn';
    case Crit = 'crit';
    case Unsupported = 'unsupported';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Warn => 'Warning',
            self::Crit => 'Critical',
            self::Unsupported => 'Unsupported',
            self::Error => 'Error',
        };
    }
}
