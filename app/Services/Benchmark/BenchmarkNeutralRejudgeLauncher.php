<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkNeutralRejudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Jobs\RunNeutralJudgement;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkNeutralRejudge;
use App\Models\BenchmarkResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts, stops and settles neutral re-judges. A re-judge covers the judged
 * results (status ok) on the cases common to the compared runs that have no
 * score from its judge yet: a judgement that failed is retried, one another
 * re-judge has queued or is scoring is left to it. The unique (result, judge)
 * key makes a concurrent start skip what the other one queued.
 */
final readonly class BenchmarkNeutralRejudgeLauncher
{
    public const string CANCELLED = 'Cancelled';

    public const string COST_CAP_REACHED = 'Cost cap reached';

    public const string STALLED = 'Judge did not finish';

    public const string JOB_FAILED = 'The judge job failed.';

    public function __construct(private BenchmarkEstimator $benchmarkEstimator) {}

    /**
     * @param  list<int>  $runIds
     * @return Collection<int, BenchmarkResult>
     */
    public function scope(array $runIds, string $judgeKey): Collection
    {
        $caseIds = collect(BenchmarkComparisonScope::commonCases($runIds))
            ->flatMap(static fn (array $task): array => $task['common'])
            ->unique()
            ->values()
            ->all();
        $judgedCaseIds = BenchmarkCase::query()
            ->whereKey($caseIds)
            ->get(['id', 'task', 'scoring'])
            ->filter(static fn (BenchmarkCase $benchmarkCase): bool => $benchmarkCase->isJudged())
            ->pluck('id')
            ->all();

        return BenchmarkResult::query()
            ->whereIn('benchmark_run_id', $runIds)
            ->whereIn('benchmark_case_id', $judgedCaseIds)
            ->where('status', BenchmarkResultStatus::Ok)
            ->whereDoesntHave('neutralJudgements', static fn (Builder $builder): Builder => $builder
                ->where('judge_key', $judgeKey)
                ->whereIn('status', [BenchmarkJudgeStatus::Ok, BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending]))
            ->orderBy('id')
            ->get(['id', 'benchmark_run_id', 'benchmark_case_id', 'completion_tokens'])
            ->toBase();
    }

    /**
     * @param  Collection<int, BenchmarkResult>  $results
     * @param  array<string, mixed>  $judge
     * @return array{total_usd: float, results: int, unpriced: list<string>}
     */
    public function estimate(Collection $results, array $judge): array
    {
        return $this->benchmarkEstimator->judgeOnly($results, $judge);
    }

    /**
     * Queue a judgement per result in scope; null when nothing is left to
     * judge (another re-judge may have taken it meanwhile).
     *
     * @param  list<int>  $runIds
     * @param  array{provider: string, model: string, reasoning: string|null}  $judge
     */
    public function start(array $runIds, array $judge, float $costCap, float $estimateUsd, User $user): ?BenchmarkNeutralRejudge
    {
        sort($runIds);
        $judgeKey = BenchmarkNeutralJudgement::keyFor($judge);

        return DB::transaction(function () use ($runIds, $judge, $judgeKey, $costCap, $estimateUsd, $user): ?BenchmarkNeutralRejudge {
            $resultIds = $this->scope($runIds, $judgeKey)->pluck('id');

            if ($resultIds->isEmpty()) {
                return null;
            }

            $benchmarkNeutralRejudge = BenchmarkNeutralRejudge::query()->create([
                'run_ids' => $runIds,
                'judge' => $judge,
                'judge_key' => $judgeKey,
                'status' => BenchmarkNeutralRejudgeStatus::Running,
                'estimate_usd' => $estimateUsd,
                'cost_cap_usd' => $costCap,
                'cost_usd' => 0,
                'created_by' => $user->id,
                'started_at' => now(),
            ]);

            BenchmarkNeutralJudgement::query()
                ->whereIn('benchmark_result_id', $resultIds)
                ->where('judge_key', $judgeKey)
                ->where('status', BenchmarkJudgeStatus::Failed)
                ->update([
                    'status' => BenchmarkJudgeStatus::Queued,
                    'benchmark_neutral_rejudge_id' => $benchmarkNeutralRejudge->id,
                    'score' => null,
                    'passed' => null,
                    'detail' => null,
                    'cost_usd' => 0,
                ]);

            $now = now();

            BenchmarkNeutralJudgement::query()->insertOrIgnore($resultIds->map(static fn (int $resultId): array => [
                'benchmark_result_id' => $resultId,
                'benchmark_neutral_rejudge_id' => $benchmarkNeutralRejudge->id,
                'judge_key' => $judgeKey,
                'judge' => json_encode($judge, JSON_THROW_ON_ERROR),
                'status' => BenchmarkJudgeStatus::Queued->value,
                'cost_usd' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            $judgementIds = $benchmarkNeutralRejudge->judgements()->where('status', BenchmarkJudgeStatus::Queued)->orderBy('id')->pluck('id');

            if ($judgementIds->isEmpty()) {
                $benchmarkNeutralRejudge->delete();

                return null;
            }

            foreach ($judgementIds as $judgementId) {
                dispatch(new RunNeutralJudgement($judgementId))->afterCommit();
            }

            return $benchmarkNeutralRejudge;
        });
    }

    /**
     * Stop a running re-judge: its queued judgements fail; one being scored
     * finishes and keeps its score. False when it had already finished.
     */
    public function cancel(BenchmarkNeutralRejudge $benchmarkNeutralRejudge): bool
    {
        return DB::transaction(function () use ($benchmarkNeutralRejudge): bool {
            $locked = BenchmarkNeutralRejudge::query()->lockForUpdate()->find($benchmarkNeutralRejudge->id);

            if (! $locked instanceof BenchmarkNeutralRejudge || $locked->status->isTerminal()) {
                return false;
            }

            $locked->update(['status' => BenchmarkNeutralRejudgeStatus::Cancelled, 'finished_at' => now()]);
            $this->failQueued($locked, self::CANCELLED);

            return true;
        });
    }

    /**
     * Complete a running re-judge once none of its judgements is queued or
     * being scored. A cancelled or capped one is never reopened.
     */
    public function settle(int $rejudgeId): void
    {
        DB::transaction(function () use ($rejudgeId): void {
            $locked = BenchmarkNeutralRejudge::query()->lockForUpdate()->find($rejudgeId);

            if (! $locked instanceof BenchmarkNeutralRejudge || $locked->status !== BenchmarkNeutralRejudgeStatus::Running) {
                return;
            }

            if ($locked->judgements()->whereIn('status', [BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending])->exists()) {
                return;
            }

            $locked->update(['status' => BenchmarkNeutralRejudgeStatus::Completed, 'finished_at' => now()]);
        });
    }

    public function stopAtCap(int $rejudgeId): void
    {
        DB::transaction(function () use ($rejudgeId): void {
            $locked = BenchmarkNeutralRejudge::query()->lockForUpdate()->find($rejudgeId);

            if (! $locked instanceof BenchmarkNeutralRejudge || $locked->status !== BenchmarkNeutralRejudgeStatus::Running) {
                return;
            }

            $locked->update(['status' => BenchmarkNeutralRejudgeStatus::StoppedCostCap, 'finished_at' => now()]);
            $this->failQueued($locked, self::COST_CAP_REACHED);
        });
    }

    /**
     * Fail judgements whose job is gone (claimed by a dead worker, or queued
     * past the unique window with a lost dispatch) and settle every running
     * re-judge with nothing left open. Judgements merely waiting in the queue
     * are left alone.
     */
    public function sweepStalled(): int
    {
        $stale = BenchmarkNeutralJudgement::query()->sweepable()->get(['id']);

        BenchmarkNeutralJudgement::query()
            ->whereKey($stale->pluck('id'))
            ->sweepable()
            ->update(['status' => BenchmarkJudgeStatus::Failed, 'detail' => json_encode(['error' => self::STALLED], JSON_THROW_ON_ERROR)]);

        $rejudgeIds = BenchmarkNeutralRejudge::query()
            ->where('status', BenchmarkNeutralRejudgeStatus::Running)
            ->pluck('id');

        foreach ($rejudgeIds as $rejudgeId) {
            $this->settle($rejudgeId);
        }

        return $stale->count();
    }

    private function failQueued(BenchmarkNeutralRejudge $benchmarkNeutralRejudge, string $reason): void
    {
        $benchmarkNeutralRejudge->judgements()
            ->where('status', BenchmarkJudgeStatus::Queued)
            ->update(['status' => BenchmarkJudgeStatus::Failed, 'detail' => json_encode(['error' => $reason], JSON_THROW_ON_ERROR)]);
    }
}
