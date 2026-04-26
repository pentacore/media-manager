<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum AiMode: string
{
    use EnumUtils;

    case Executive = 'executive';
    case Advisory = 'advisory';

    public function label(): string
    {
        return match ($this) {
            self::Executive => 'Executive',
            self::Advisory => 'Advisory',
        };
    }
}
