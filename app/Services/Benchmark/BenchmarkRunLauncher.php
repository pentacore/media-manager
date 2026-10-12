<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Jobs\RunBenchmarkCase;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Starts, cancels, deletes and re-judges benchmark runs: one pending result and one
 * queued RunBenchmarkCase job per case × candidate.
 */
final readonly class BenchmarkRunLauncher
{
    public function __construct(private BenchmarkEstimator $benchmarkEstimator) {}

    /**
     * @param  Collection<int, BenchmarkCase>  $cases
     * @param  list<array<string, mixed>>  $candidates  `{provider, model, reasoning}` selections
     * @param  array<string, mixed>  $judge
     */
    public function launch(string $name, Collection $cases, array $candidates, array $judge, ?float $costCap, User $user): BenchmarkRun
    {
        throw_if($cases->isEmpty(), InvalidArgumentException::class, 'A benchmark run needs at least one case.');
        throw_if($candidates === [], InvalidArgumentException::class, 'A benchmark run needs at least one candidate.');

        $estimate = $this->benchmarkEstimator->estimate($cases, $candidates, $judge);

        return DB::transaction(function () use ($name, $cases, $candidates, $judge, $costCap, $user, $estimate): BenchmarkRun {
            $benchmarkRun = BenchmarkRun::query()->create([
                'name' => $name,
                'status' => BenchmarkRunStatus::Running,
                'candidates' => $candidates,
                'judge' => $judge,
                'case_ids' => $cases->pluck('id')->values()->all(),
                'estimate_usd' => $estimate['total_usd'],
                'cost_cap_usd' => $costCap,
                'cost_usd' => 0,
                'started_at' => now(),
                'created_by' => $user->id,
            ]);

            foreach ($cases as $case) {
                foreach (array_keys($candidates) as $candidateIndex) {
                    $benchmarkResult = $benchmarkRun->results()->create([
                        'benchmark_case_id' => $case->id,
                        'case_name' => $case->name,
                        'case_task' => $case->task->value,
                        'candidate_index' => $candidateIndex,
                        'status' => BenchmarkResultStatus::Pending,
                        'intended_actions' => [],
                        'judge_status' => BenchmarkJudgeStatus::None,
                    ]);

                    dispatch(new RunBenchmarkCase($benchmarkResult->id))->afterCommit();
                }
            }

            return $benchmarkRun;
        });
    }

    /**
     * Stop a running run: its pending results are skipped; a running result's
     * job finishes, keeps its result and settles the run's cost.
     */
    public function cancel(BenchmarkRun $benchmarkRun): void
    {
        DB::transaction(function () use ($benchmarkRun): void {
            $lockedRun = BenchmarkRun::query()->lockForUpdate()->findOrFail($benchmarkRun->id);

            if ($lockedRun->isTerminal()) {
                return;
            }

            $lockedRun->update(['status' => BenchmarkRunStatus::Cancelled, 'finished_at' => now()]);
            $lockedRun->results()->where('status', BenchmarkResultStatus::Pending)->update(['status' => BenchmarkResultStatus::Skipped]);
        });
    }

    /**
     * Delete a finished run with its results, under a lock on the run. Refused
     * (with the reason) while the run is going or a job still works on one of
     * its results: that job's usage rows reference the run.
     */
    public function delete(BenchmarkRun $benchmarkRun): ?string
    {
        return DB::transaction(function () use ($benchmarkRun): ?string {
            $lockedRun = BenchmarkRun::query()->lockForUpdate()->find($benchmarkRun->id);

            if (! $lockedRun instanceof BenchmarkRun) {
                return null;
            }

            if (! $lockedRun->isTerminal()) {
                return __('Cancel the run first.');
            }

            if ($lockedRun->results()->inFlight()->exists()) {
                return __('Wait for the running cases to finish.');
            }

            $lockedRun->delete();

            return null;
        });
    }

    /**
     * Mark every unscored result whose case still exists `queued` and queue a
     * judge-only job for it, so the run page shows (and polls) the re-judge
     * at once. Only `failed` results and re-judges that stalled (queued or
     * pending past the stale window: a dropped dispatch or a dead worker)
     * are flipped, under a row lock, so a double submit queues each result
     * once. Returns how many were queued.
     */
    public function rejudge(BenchmarkRun $benchmarkRun): int
    {
        return DB::transaction(function () use ($benchmarkRun): int {
            $resultIds = $benchmarkRun->results()
                ->where('status', BenchmarkResultStatus::Ok)
                ->where(fn (Builder $builder): Builder => $builder
                    ->where('judge_status', BenchmarkJudgeStatus::Failed)
                    ->orWhere(fn (Builder $stale): Builder => $stale->judgeStale()))
                ->whereNotNull('benchmark_case_id')
                ->lockForUpdate()
                ->pluck('id');

            BenchmarkResult::query()->whereKey($resultIds)->update(['judge_status' => BenchmarkJudgeStatus::Queued]);

            foreach ($resultIds as $resultId) {
                dispatch(new RunBenchmarkCase($resultId, judgeOnly: true))->afterCommit();
            }

            return $resultIds->count();
        });
    }
}
