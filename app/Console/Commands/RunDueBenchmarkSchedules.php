<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Providers\AIServiceProvider;
use App\Services\Benchmark\BenchmarkScheduleLauncher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Description('Launch every enabled benchmark schedule whose next run is due.')]
#[Signature('benchmark:run-due-schedules')]
class RunDueBenchmarkSchedules extends Command
{
    public function handle(BenchmarkScheduleLauncher $benchmarkScheduleLauncher): int
    {
        if (! AIServiceProvider::enabled()) {
            $this->info('AI is disabled; no benchmark schedule was launched.');

            return self::SUCCESS;
        }

        $scheduleIds = BenchmarkSchedule::query()
            ->where('enabled', true)
            ->where('next_run_at', '<=', now())
            ->oldest('next_run_at')
            ->pluck('id');

        $launched = 0;

        foreach ($scheduleIds as $scheduleId) {
            try {
                $benchmarkScheduleAttempt = $benchmarkScheduleLauncher->launchDue($scheduleId);
            } catch (Throwable $throwable) {
                report($throwable);

                continue;
            }

            if ($benchmarkScheduleAttempt?->benchmarkRun instanceof BenchmarkRun) {
                $launched++;
            }
        }

        $this->info(sprintf('Launched %d of %d due benchmark schedule(s).', $launched, $scheduleIds->count()));

        return self::SUCCESS;
    }
}
