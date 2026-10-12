<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Jobs\RunBenchmarkCase;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Starts, cancels and re-judges benchmark runs: one pending result and one
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
                        'judge_status' => BenchmarkScorer::JUDGE_NONE,
                    ]);

                    dispatch(new RunBenchmarkCase($benchmarkResult->id))->afterCommit();
                }
            }

            return $benchmarkRun;
        });
    }

    /**
     * Stop a running run: its pending results are skipped (a job already
     * running finishes and keeps its result).
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
     * Queue a judge-only job for every result whose judge failed.
     */
    public function rejudge(BenchmarkRun $benchmarkRun): void
    {
        $resultIds = $benchmarkRun->results()
            ->where('status', BenchmarkResultStatus::Ok)
            ->where('judge_status', BenchmarkScorer::JUDGE_FAILED)
            ->pluck('id');

        foreach ($resultIds as $resultId) {
            dispatch(new RunBenchmarkCase($resultId, judgeOnly: true));
        }
    }
}
