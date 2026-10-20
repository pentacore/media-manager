<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Services\Benchmark\BenchmarkScheduleLauncher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Launch every enabled benchmark schedule whose next run is due.')]
#[Signature('benchmark:run-due-schedules')]
class RunDueBenchmarkSchedules extends Command
{
    public function handle(BenchmarkScheduleLauncher $benchmarkScheduleLauncher): int
    {
        $scheduleIds = BenchmarkSchedule::query()
            ->where('enabled', true)
            ->where('next_run_at', '<=', now())
            ->oldest('next_run_at')
            ->pluck('id');

        $launched = 0;

        foreach ($scheduleIds as $scheduleId) {
            $benchmarkScheduleAttempt = $benchmarkScheduleLauncher->launchDue($scheduleId);

            if ($benchmarkScheduleAttempt?->benchmarkRun instanceof BenchmarkRun) {
                $launched++;
            }
        }

        $this->info(sprintf('Launched %d of %d due benchmark schedule(s).', $launched, $scheduleIds->count()));

        return self::SUCCESS;
    }
}
