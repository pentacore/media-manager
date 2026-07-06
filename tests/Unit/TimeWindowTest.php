<?php

declare(strict_types=1);

use App\Enums\TimeWindow;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('rolling windows walk back from now', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 14:30:00'));

    expect(TimeWindow::Last24h->cutoff()?->toDateTimeString())->toBe('2026-07-14 14:30:00')
        ->and(TimeWindow::Last7d->cutoff()?->toDateTimeString())->toBe('2026-07-08 14:30:00')
        ->and(TimeWindow::Last30d->cutoff()?->toDateTimeString())->toBe('2026-06-15 14:30:00')
        ->and(TimeWindow::Last90d->cutoff()?->toDateTimeString())->toBe('2026-04-16 14:30:00');
});

test('calendar windows snap to period starts in the app timezone', function (): void {
    // 2026-07-15 is a Wednesday.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 14:30:00'));

    expect(TimeWindow::Today->cutoff()?->toDateTimeString())->toBe('2026-07-15 00:00:00')
        ->and(TimeWindow::ThisWeek->cutoff()?->toDateTimeString())->toBe('2026-07-13 00:00:00')
        ->and(TimeWindow::ThisMonth->cutoff()?->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and(TimeWindow::ThisYear->cutoff()?->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

test('all has no cutoff', function (): void {
    expect(TimeWindow::All->cutoff())->toBeNull();
});

test('fromRequest accepts canonical values', function (): void {
    expect(TimeWindow::fromRequest('today'))->toBe(TimeWindow::Today)
        ->and(TimeWindow::fromRequest('24h'))->toBe(TimeWindow::Last24h)
        ->and(TimeWindow::fromRequest('week'))->toBe(TimeWindow::ThisWeek)
        ->and(TimeWindow::fromRequest('all'))->toBe(TimeWindow::All);
});

test('fromRequest maps legacy watch-history day counts', function (): void {
    expect(TimeWindow::fromRequest('1'))->toBe(TimeWindow::Last24h)
        ->and(TimeWindow::fromRequest('7'))->toBe(TimeWindow::Last7d)
        ->and(TimeWindow::fromRequest('30'))->toBe(TimeWindow::Last30d)
        ->and(TimeWindow::fromRequest('90'))->toBe(TimeWindow::Last90d);
});

test('fromRequest falls back to the default for unknown values', function (): void {
    expect(TimeWindow::fromRequest('forever'))->toBe(TimeWindow::Last7d)
        ->and(TimeWindow::fromRequest(null))->toBe(TimeWindow::Last7d)
        ->and(TimeWindow::fromRequest('forever', TimeWindow::Last24h))->toBe(TimeWindow::Last24h);
});

test('options returns display-ordered value/label pairs', function (): void {
    $options = TimeWindow::options();

    expect(array_column($options, 'value'))->toBe(['today', '24h', '7d', '30d', '90d', 'week', 'month', 'year', 'all'])
        ->and($options[0])->toBe(['value' => 'today', 'label' => 'Today'])
        ->and($options[5])->toBe(['value' => 'week', 'label' => 'This week'])
        ->and($options[8])->toBe(['value' => 'all', 'label' => 'All']);
});
