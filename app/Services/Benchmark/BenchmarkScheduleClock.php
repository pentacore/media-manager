<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkScheduleFrequency;
use App\Models\BenchmarkSchedule;
use Carbon\CarbonImmutable;

/**
 * When a benchmark schedule next fires: the first slot strictly after a
 * moment, on the schedule's wall-clock time in the app timezone. Days of
 * month stop at 28, so every month has the slot.
 *
 * Each slot is built from its calendar date, so daylight saving never drifts
 * it: a time the spring-forward gap skips runs that many minutes later on
 * that day only, and a time the fall-back overlap repeats runs once, at its
 * second (standard-time) occurrence.
 */
final readonly class BenchmarkScheduleClock
{
    public function nextRunAfter(BenchmarkSchedule $benchmarkSchedule, CarbonImmutable $after): CarbonImmutable
    {
        $timezone = (string) config('app.timezone');
        $local = $after->setTimezone($timezone)->startOfDay();
        [$hour, $minute] = array_map(intval(...), explode(':', $benchmarkSchedule->time_of_day));

        $date = match ($benchmarkSchedule->frequency) {
            BenchmarkScheduleFrequency::Daily => $local,
            BenchmarkScheduleFrequency::Weekly => $local->addDays((($benchmarkSchedule->weekday ?? 1) - $local->dayOfWeekIso + 7) % 7),
            BenchmarkScheduleFrequency::Monthly => $local->setDate($local->year, $local->month, $benchmarkSchedule->day_of_month ?? 1),
        };

        $slot = $this->slotOn($date, $hour, $minute, $timezone);

        if ($slot->lessThanOrEqualTo($after)) {
            $date = match ($benchmarkSchedule->frequency) {
                BenchmarkScheduleFrequency::Daily => $date->addDay(),
                BenchmarkScheduleFrequency::Weekly => $date->addWeek(),
                BenchmarkScheduleFrequency::Monthly => $date->addMonthNoOverflow(),
            };

            return $this->slotOn($date, $hour, $minute, $timezone);
        }

        return $slot;
    }

    private function slotOn(CarbonImmutable $date, int $hour, int $minute, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::create($date->year, $date->month, $date->day, $hour, $minute, 0, $timezone);
    }
}
