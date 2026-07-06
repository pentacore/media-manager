<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;
use Carbon\CarbonImmutable;

enum RateLimitPeriod: string
{
    use EnumUtils;

    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';

    public function label(): string
    {
        return match ($this) {
            self::Minute => 'Per minute',
            self::Hour => 'Per hour',
            self::Day => 'Per day',
        };
    }

    /**
     * Start of the rolling window ending now, in UTC. Provider rate
     * limits are rolling (the last 60s/3600s/86400s), unlike
     * FreeUsagePeriod's calendar resets.
     */
    public function windowStart(): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return match ($this) {
            self::Minute => $now->subMinute(),
            self::Hour => $now->subHour(),
            self::Day => $now->subDay(),
        };
    }
}
