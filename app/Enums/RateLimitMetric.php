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

    /**
     * Value/label option pairs for select components.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $metric): array => ['value' => $metric->value, 'label' => $metric->label()],
            self::cases(),
        );
    }
}
