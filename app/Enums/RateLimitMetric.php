<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum RateLimitMetric: string
{
    use EnumUtils;

    case Requests = 'requests';
    case Tokens = 'tokens';

    public function label(): string
    {
        return match ($this) {
            self::Requests => 'Requests',
            self::Tokens => 'Tokens',
        };
    }
}
