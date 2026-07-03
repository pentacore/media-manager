<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum WhisparrVersion: string
{
    use EnumUtils;

    case V2 = 'v2';
    case V3 = 'v3';

    public function label(): string
    {
        return match ($this) {
            self::V2 => 'v2 (Eros / series-based)',
            self::V3 => 'v3 (movie-based)',
        };
    }

    /**
     * The upstream API resource segment this version manages
     * (`/api/v3/{resource}`).
     */
    public function resource(): string
    {
        return match ($this) {
            self::V2 => 'series',
            self::V3 => 'movie',
        };
    }
}
