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
 */
final readonly class BenchmarkScheduleClock
{
    public function nextRunAfter(BenchmarkSchedule $benchmarkSchedule, CarbonImmutable $after): CarbonImmutable
    {
        $local = $after->setTimezone((string) config('app.timezone'));
        [$hour, $minute] = array_map(intval(...), explode(':', $benchmarkSchedule->time_of_day));

        $slot = match ($benchmarkSchedule->frequency) {
            BenchmarkScheduleFrequency::Daily => $local->setTime($hour, $minute),
            BenchmarkScheduleFrequency::Weekly => $local
                ->addDays((($benchmarkSchedule->weekday ?? 1) - $local->dayOfWeekIso + 7) % 7)
                ->setTime($hour, $minute),
            BenchmarkScheduleFrequency::Monthly => $local
                ->setDate($local->year, $local->month, $benchmarkSchedule->day_of_month ?? 1)
                ->setTime($hour, $minute),
        };

        if ($slot->lessThanOrEqualTo($local)) {
            return match ($benchmarkSchedule->frequency) {
                BenchmarkScheduleFrequency::Daily => $slot->addDay(),
                BenchmarkScheduleFrequency::Weekly => $slot->addWeek(),
                BenchmarkScheduleFrequency::Monthly => $slot->addMonthNoOverflow(),
            };
        }

        return $slot;
    }
}
