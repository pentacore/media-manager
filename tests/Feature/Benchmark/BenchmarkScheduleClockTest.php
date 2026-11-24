<?php

declare(strict_types=1);

use App\Enums\BenchmarkScheduleFrequency;
use App\Models\BenchmarkSchedule;
use App\Services\Benchmark\BenchmarkScheduleClock;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $attributes
 */
function clockSchedule(array $attributes): BenchmarkSchedule
{
    return new BenchmarkSchedule(['time_of_day' => '03:00', ...$attributes]);
}

function nextRun(BenchmarkSchedule $benchmarkSchedule, string $after): string
{
    return resolve(BenchmarkScheduleClock::class)
        ->nextRunAfter($benchmarkSchedule, CarbonImmutable::parse($after, config('app.timezone')))
        ->format('Y-m-d H:i');
}

beforeEach(function (): void {
    config()->set('app.timezone', 'Europe/Stockholm');
});

test('a daily schedule runs later today or tomorrow', function (): void {
    $benchmarkSchedule = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Daily]);

    expect(nextRun($benchmarkSchedule, '2026-10-20 02:59'))->toBe('2026-10-20 03:00')
        ->and(nextRun($benchmarkSchedule, '2026-10-20 03:00'))->toBe('2026-10-21 03:00')
        ->and(nextRun($benchmarkSchedule, '2026-12-31 23:00'))->toBe('2027-01-01 03:00');
});

test('a weekly schedule runs on its weekday', function (): void {
    // 2026-10-20 is a Tuesday.
    $benchmarkSchedule = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Weekly, 'weekday' => 1]);
    $tuesday = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Weekly, 'weekday' => 2]);

    expect(nextRun($benchmarkSchedule, '2026-10-20 12:00'))->toBe('2026-10-26 03:00')
        ->and(nextRun($tuesday, '2026-10-20 02:00'))->toBe('2026-10-20 03:00')
        ->and(nextRun($tuesday, '2026-10-20 03:00'))->toBe('2026-10-27 03:00');
});

test('a monthly schedule runs on its day of month', function (): void {
    $benchmarkSchedule = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Monthly, 'day_of_month' => 1]);
    $last = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Monthly, 'day_of_month' => 28]);

    expect(nextRun($benchmarkSchedule, '2026-10-20 12:00'))->toBe('2026-11-01 03:00')
        ->and(nextRun($last, '2026-10-20 12:00'))->toBe('2026-10-28 03:00')
        ->and(nextRun($last, '2027-01-28 03:00'))->toBe('2027-02-28 03:00')
        ->and(nextRun($benchmarkSchedule, '2026-12-05 00:00'))->toBe('2027-01-01 03:00');
});

test('the slot is computed in the app timezone', function (): void {
    $benchmarkSchedule = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Daily]);

    $next = resolve(BenchmarkScheduleClock::class)->nextRunAfter($benchmarkSchedule, CarbonImmutable::parse('2026-10-20 00:30', 'UTC'));

    // 00:30 UTC is 02:30 in Stockholm (CEST), so today's 03:00 local is still ahead.
    expect($next->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-10-20 03:00');
});

test('the slot keeps its wall-clock time across a daylight saving change', function (): void {
    $benchmarkSchedule = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Daily]);

    // Stockholm leaves summer time on 2026-10-25 at 03:00.
    expect(nextRun($benchmarkSchedule, '2026-10-25 12:00'))->toBe('2026-10-26 03:00');
});

test('a slot in the spring-forward gap runs after the gap that day and back on time the next', function (): void {
    $benchmarkSchedule = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Daily, 'time_of_day' => '02:30']);

    // Stockholm skips 02:00–03:00 on 2027-03-28: that day's slot moves to 03:30.
    expect(nextRun($benchmarkSchedule, '2027-03-27 12:00'))->toBe('2027-03-28 03:30')
        ->and(nextRun($benchmarkSchedule, '2027-03-28 03:45'))->toBe('2027-03-29 02:30');
});

test('a weekly or monthly slot shifted by the gap is back on time a period later', function (): void {
    // 2027-03-28 is a Sunday.
    $weekly = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Weekly, 'weekday' => 7, 'time_of_day' => '02:30']);
    $monthly = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Monthly, 'day_of_month' => 28, 'time_of_day' => '02:30']);

    expect(nextRun($weekly, '2027-03-28 03:45'))->toBe('2027-04-04 02:30')
        ->and(nextRun($monthly, '2027-03-28 03:45'))->toBe('2027-04-28 02:30');
});

test('a slot in the fall-back overlap runs once that day', function (): void {
    $benchmarkSchedule = clockSchedule(['frequency' => BenchmarkScheduleFrequency::Daily, 'time_of_day' => '02:30']);
    $clock = resolve(BenchmarkScheduleClock::class);
    $at = static fn (string $utc): string => $clock->nextRunAfter($benchmarkSchedule, CarbonImmutable::parse($utc, 'UTC'))->setTimezone('UTC')->format('Y-m-d H:i');

    // 2026-10-25 02:30 happens twice in Stockholm: 00:30 UTC (CEST) and 01:30 UTC (CET).
    // The slot is always the CET one, so the first pass never fires it.
    expect($at('2026-10-24 12:00'))->toBe('2026-10-25 01:30')
        ->and($at('2026-10-25 00:30'))->toBe('2026-10-25 01:30')
        ->and($at('2026-10-25 01:30'))->toBe('2026-10-26 01:30');
});
