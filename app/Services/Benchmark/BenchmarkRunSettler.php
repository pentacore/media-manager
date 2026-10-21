<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Jobs\CheckBenchmarkRegression;
use App\Models\BenchmarkRun;
use App\Services\AiUsage\AiUsageReporting;
use Illuminate\Support\Facades\DB;

/**
 * Brings a benchmark run's bookkeeping up to date after a result changed:
 * its cost, its completion and its cost-cap stop. Shared by the result job
 * and the scheduled sweep that recovers runs whose worker died.
 */
final readonly class BenchmarkRunSettler
{
    /** The error of a result whose job died after its run had already ended. */
    public const string STOPPED_ERROR = 'Stopped before finishing';

    /** The error of a result whose claim went stale in a run that is still going. */
    public const string STALLED_ERROR = 'Worker lost before finishing';

    public function __construct(private AiUsageReporting $aiUsageReporting) {}

    /**
     * Under a lock on the run: bring its cost up to what its usage rows
     * (candidates and judges, finished or failed) have spent — never down,
     * so pruned usage rows can't shrink it — then, unless it already ended,
     * complete it once no result is pending or running, or stop it at its
     * cost cap (skipping every pending result; a running one finishes and
     * keeps its result) once the total reached the cap. In a run that already
     * ended, a result still running under a stale claim lost its worker and
     * will never finish: it fails with a fixed reason. A scheduled run that
     * finishes (completed or stopped at its cap) queues its regression check.
     * With $failStalled a run that is still going fails those results too, so
     * it can complete.
     */
    public function settle(int $runId, bool $failStalled = false): void
    {
        DB::transaction(function () use ($runId, $failStalled): void {
            $benchmarkRun = BenchmarkRun::query()->lockForUpdate()->find($runId);

            if (! $benchmarkRun instanceof BenchmarkRun) {
                return;
            }

            $spentUsd = $this->aiUsageReporting->benchmarkRunCost($runId);

            if ($spentUsd > (float) $benchmarkRun->cost_usd) {
                $benchmarkRun->update(['cost_usd' => $spentUsd]);
            }

            if ($benchmarkRun->isTerminal()) {
                $benchmarkRun->results()->runStale()->update([
                    'status' => BenchmarkResultStatus::Failed,
                    'error' => self::STOPPED_ERROR,
                ]);

                return;
            }

            if ($failStalled) {
                $benchmarkRun->results()->runStale()->update([
                    'status' => BenchmarkResultStatus::Failed,
                    'error' => self::STALLED_ERROR,
                ]);
            }

            $unfinished = $benchmarkRun->results()->whereIn('status', [BenchmarkResultStatus::Pending, BenchmarkResultStatus::Running]);

            if (! $unfinished->exists()) {
                $benchmarkRun->update(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);
                $this->queueRegressionCheck($benchmarkRun);

                return;
            }

            if ($this->costCapReached($benchmarkRun)) {
                $benchmarkRun->results()->where('status', BenchmarkResultStatus::Pending)->update(['status' => BenchmarkResultStatus::Skipped]);
                $benchmarkRun->update(['status' => BenchmarkRunStatus::StoppedCostCap, 'finished_at' => now()]);
                $this->queueRegressionCheck($benchmarkRun);
            }
        });
    }

    /**
     * A scheduled run that just finished gets compared with its schedule's
     * previous run once the settle commits.
     */
    private function queueRegressionCheck(BenchmarkRun $benchmarkRun): void
    {
        if ($benchmarkRun->benchmark_schedule_id !== null) {
            dispatch(new CheckBenchmarkRegression($benchmarkRun->id))->afterCommit();
        }
    }

    private function costCapReached(BenchmarkRun $benchmarkRun): bool
    {
        return $benchmarkRun->cost_cap_usd !== null && (float) $benchmarkRun->cost_usd >= (float) $benchmarkRun->cost_cap_usd;
    }
}
