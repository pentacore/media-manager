<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum NotificationSeverity: string
{
    use EnumUtils;

    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Error => 'Error',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }

    /** Whether a raw severity string meets this threshold. Unknown strings never do. */
    public function atLeast(string $severity): bool
    {
        $other = self::tryFrom($severity);

        return $other instanceof self && $other->rank() >= $this->rank();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $severity): array => ['value' => $severity->value, 'label' => $severity->label()],
            self::cases(),
        );
    }
}
