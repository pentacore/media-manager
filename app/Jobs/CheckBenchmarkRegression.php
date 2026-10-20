<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueLane;
use App\Models\BenchmarkRun;
use App\Notifications\ScheduledBenchmarkAlert;
use App\Services\Benchmark\BenchmarkRegressionDetector;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Compares a finished scheduled benchmark run with its schedule's previous
 * comparable run, stores the comparison on the run and alerts admins when a
 * model regressed. A run whose comparison is stored is never checked again.
 */
#[Queue(QueueLane::Default)]
#[Timeout(60)]
#[Tries(3)]
final class CheckBenchmarkRegression implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $runId) {}

    public function uniqueId(): string
    {
        return sprintf('benchmark-regression:%d', $this->runId);
    }

    public function handle(BenchmarkRegressionDetector $benchmarkRegressionDetector, AdminNotifier $adminNotifier): void
    {
        $benchmarkRun = BenchmarkRun::query()->with('schedule')->find($this->runId);

        if (! $benchmarkRun instanceof BenchmarkRun || $benchmarkRun->regressions !== null) {
            return;
        }

        $detected = $benchmarkRegressionDetector->detect($benchmarkRun);

        $benchmarkRun->update(['baseline_run_id' => $detected['baseline_run_id'], 'regressions' => $detected['regressions']]);

        if ($detected['regressions'] !== [] && $benchmarkRun->schedule !== null) {
            $adminNotifier->send(ScheduledBenchmarkAlert::regression($benchmarkRun->schedule, $benchmarkRun, $detected['regressions']));
        }
    }
}
