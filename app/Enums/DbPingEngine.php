<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum DbPingEngine: string
{
    use EnumUtils;

    case Postgres = 'postgres';
    case Mysql = 'mysql';

    public function label(): string
    {
        return match ($this) {
            self::Postgres => 'PostgreSQL',
            self::Mysql => 'MySQL',
        };
    }
}
