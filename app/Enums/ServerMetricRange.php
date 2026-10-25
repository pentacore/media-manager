<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum ServerMetricRange: string
{
    use EnumUtils;

    private const int MAX_BUCKETS = 120;

    case SixHours = '6h';
    case TwoDays = '48h';
    case ThirtyDays = '30d';
    case OneYear = '1y';

    public function label(): string
    {
        return match ($this) {
            self::SixHours => 'Last 6 hours',
            self::TwoDays => 'Last 48 hours',
            self::ThirtyDays => 'Last 30 days',
            self::OneYear => 'Last year',
        };
    }

    public function seconds(): int
    {
        return match ($this) {
            self::SixHours => 6 * 3600,
            self::TwoDays => 48 * 3600,
            self::ThirtyDays => 30 * 86400,
            self::OneYear => 365 * 86400,
        };
    }

    /** The stored sample resolution (seconds) this range reads from. */
    public function resolution(): int
    {
        return match ($this) {
            self::SixHours, self::TwoDays => 60,
            self::ThirtyDays => 300,
            self::OneYear => 3600,
        };
    }

    /** Chart bucket width: the stored resolution, widened so a range never exceeds 120 points. */
    public function bucketSeconds(): int
    {
        return max($this->resolution(), intdiv($this->seconds(), self::MAX_BUCKETS));
    }

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::SixHours;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $range): array => ['value' => $range->value, 'label' => $range->label()],
            self::cases(),
        );
    }
}
