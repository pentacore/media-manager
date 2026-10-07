<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum AiReasoningLevel: string
{
    use EnumUtils;

    case ProviderDefault = 'provider_default';
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';

    public function label(): string
    {
        return match ($this) {
            self::ProviderDefault => 'Provider default',
            self::None => 'None',
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::XHigh => 'Extra high',
            self::Max => 'Max',
        };
    }

    /**
     * Whether this level is sent to a provider. ProviderDefault means "send
     * no reasoning parameter at all".
     */
    public function isSendable(): bool
    {
        return $this !== self::ProviderDefault;
    }

    /**
     * Position on the effort scale, used to clamp to a model's supported
     * levels. ProviderDefault sits outside the scale.
     */
    public function rank(): int
    {
        return match ($this) {
            self::ProviderDefault => -1,
            self::None => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::XHigh => 4,
            self::Max => 5,
        };
    }

    /**
     * @return list<self>
     */
    public static function sendable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $level): bool => $level->isSendable()));
    }

    /**
     * @return array<int, non-empty-array<string, string>>
     */
    public static function mapForSelect(bool $withNull = false, string $labelKey = 'name'): array
    {
        return array_map(
            static fn (self $level): array => [$labelKey => $level->label(), 'value' => $level->value],
            self::cases(),
        );
    }
}
