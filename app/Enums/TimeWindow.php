<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Time-scale filter for dashboard tables (AI usage, watch history).
 *
 * Cutoffs run on the APP timezone because these are display filters —
 * unlike FreeUsagePeriod, which is pinned to UTC for provider accounting.
 * Case order is display order for the shared filter component.
 */
enum TimeWindow: string
{
    use EnumUtils;

    case Today = 'today';
    case Last24h = '24h';
    case Last7d = '7d';
    case Last30d = '30d';
    case Last90d = '90d';
    case ThisWeek = 'week';
    case ThisMonth = 'month';
    case ThisYear = 'year';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Last24h => '24h',
            self::Last7d => '7d',
            self::Last30d => '30d',
            self::Last90d => '90d',
            self::ThisWeek => 'This week',
            self::ThisMonth => 'This month',
            self::ThisYear => 'This year',
            self::All => 'All',
        };
    }

    /**
     * Lower bound for the window, or null when the window is unbounded.
     */
    public function cutoff(): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return match ($this) {
            self::Today => $now->startOfDay(),
            self::Last24h => $now->subDay(),
            self::Last7d => $now->subDays(7),
            self::Last30d => $now->subDays(30),
            self::Last90d => $now->subDays(90),
            self::ThisWeek => $now->startOfWeek(CarbonInterface::MONDAY),
            self::ThisMonth => $now->startOfMonth(),
            self::ThisYear => $now->startOfYear(),
            self::All => null,
        };
    }

    /**
     * Resolve a query-string value, tolerating the legacy watch-history
     * day-count scheme (?since=1|7|30|90) so bookmarked URLs keep working.
     */
    public static function fromRequest(?string $raw, self $default = self::Last7d): self
    {
        $direct = self::tryFrom((string) $raw);

        if ($direct !== null) {
            return $direct;
        }

        return match ($raw) {
            '1' => self::Last24h,
            '7' => self::Last7d,
            '30' => self::Last30d,
            '90' => self::Last90d,
            default => $default,
        };
    }

    /**
     * Display-ordered options for the shared TimeWindowFilter component.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $timeWindow): array => ['value' => $timeWindow->value, 'label' => $timeWindow->label()],
            self::cases(),
        );
    }
}
