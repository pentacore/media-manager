<?php

declare(strict_types=1);

use App\Enums\RateLimitPeriod;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('windowStart returns a rolling window ending now', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 13:45:30', 'UTC'));

    expect(RateLimitPeriod::Minute->windowStart()->toIso8601String())->toBe('2026-07-15T13:44:30+00:00')
        ->and(RateLimitPeriod::Hour->windowStart()->toIso8601String())->toBe('2026-07-15T12:45:30+00:00')
        ->and(RateLimitPeriod::Day->windowStart()->toIso8601String())->toBe('2026-07-14T13:45:30+00:00');
});

test('windowStart crosses day boundaries correctly', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 00:00:30', 'UTC'));

    expect(RateLimitPeriod::Hour->windowStart()->toIso8601String())->toBe('2026-06-30T23:00:30+00:00');
});

test('enums expose backed values and labels', function (): void {
    expect(RateLimitPeriod::values())->toBe(['minute', 'hour', 'day'])
        ->and(RateLimitPeriod::Minute->label())->toBe('Per minute')
        ->and(\App\Enums\RateLimitMetric::values())->toBe(['requests', 'tokens'])
        ->and(\App\Enums\RateLimitMetric::Requests->label())->toBe('Requests');
});
