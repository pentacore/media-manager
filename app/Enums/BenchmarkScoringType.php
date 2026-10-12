<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum BenchmarkScoringType: string
{
    use EnumUtils;

    case Actions = 'actions';
    case Prices = 'prices';
    case Judge = 'judge';

    public function label(): string
    {
        return match ($this) {
            self::Actions => 'Actions',
            self::Prices => 'Prices',
            self::Judge => 'Judge',
        };
    }
}
