<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum ServerStatus: string
{
    use EnumUtils;

    case Pending = 'pending';
    case Ok = 'ok';
    case Incident = 'incident';
    case Silent = 'silent';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Ok => 'OK',
            self::Incident => 'Incident',
            self::Silent => 'Silent',
        };
    }
}
