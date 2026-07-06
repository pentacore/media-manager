<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum FreeUsagePeriod: string
{
    use EnumUtils;

    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }

    /**
     * Start of the currently running period, on the UTC calendar. Providers
     * reset free tiers on UTC boundaries, so pool accounting must too —
     * regardless of the app display timezone.
     */
    public function currentPeriodStart(): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return match ($this) {
            self::Daily => $now->startOfDay(),
            self::Weekly => $now->startOfWeek(CarbonInterface::MONDAY),
            self::Monthly => $now->startOfMonth(),
        };
    }

    /**
     * Matching granularity for Postgres date_trunc(); date_trunc('week', …)
     * is ISO (Monday-based), which lines up with currentPeriodStart().
     */
    public function sqlDateTrunc(): string
    {
        return match ($this) {
            self::Daily => 'day',
            self::Weekly => 'week',
            self::Monthly => 'month',
        };
    }
}
