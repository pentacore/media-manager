<?php

declare(strict_types=1);

use App\Enums\FreeUsagePeriod;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('daily period starts at UTC midnight', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-08 22:30:00', 'UTC'));

    $start = FreeUsagePeriod::Daily->currentPeriodStart();

    expect($start->toIso8601String())->toBe('2026-07-08T00:00:00+00:00');
});

test('weekly period starts on Monday UTC', function (): void {
    // 2026-07-08 is a Wednesday; the ISO week starts Monday 2026-07-06.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-08 10:00:00', 'UTC'));

    $start = FreeUsagePeriod::Weekly->currentPeriodStart();

    expect($start->toIso8601String())->toBe('2026-07-06T00:00:00+00:00');
});

test('monthly period starts on the first of the month UTC', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-31 23:59:59', 'UTC'));

    $start = FreeUsagePeriod::Monthly->currentPeriodStart();

    expect($start->toIso8601String())->toBe('2026-07-01T00:00:00+00:00');
});

test('sqlDateTrunc maps periods to postgres granularities', function (): void {
    expect(FreeUsagePeriod::Daily->sqlDateTrunc())->toBe('day');
    expect(FreeUsagePeriod::Weekly->sqlDateTrunc())->toBe('week');
    expect(FreeUsagePeriod::Monthly->sqlDateTrunc())->toBe('month');
});

test('labels are human readable', function (): void {
    expect(FreeUsagePeriod::Daily->label())->toBe('Daily');
    expect(FreeUsagePeriod::Weekly->label())->toBe('Weekly');
    expect(FreeUsagePeriod::Monthly->label())->toBe('Monthly');
});
