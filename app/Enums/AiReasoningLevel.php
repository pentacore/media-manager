<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum AiReasoningLevel: string
{
    use EnumUtils;

    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';

    /**
     * @return array<int, non-empty-array<string, string>>
     */
    public static function mapForSelect(bool $withNull = false, string $labelKey = 'name'): array
    {
        return [
            [
                $labelKey => self::None->name,
                'value' => self::None->value,
            ],
            [
                $labelKey => self::Low->name,
                'value' => self::Low->value,
            ],
            [
                $labelKey => self::Medium->name,
                'value' => self::Medium->value,
            ],
            [
                $labelKey => self::High->name,
                'value' => self::High->value,
            ],
            [
                $labelKey => self::XHigh->name,
                'value' => self::XHigh->value,
            ],
            [
                $labelKey => self::Max->name,
                'value' => self::Max->value,
            ],
        ];
    }
}
