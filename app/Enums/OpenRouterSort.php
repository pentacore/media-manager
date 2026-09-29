<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum OpenRouterSort: string
{
    use EnumUtils;

    case Price = 'price';
    case Throughput = 'throughput';
    case Latency = 'latency';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'Lowest price',
            self::Throughput => 'Highest throughput',
            self::Latency => 'Lowest latency',
        };
    }
}
