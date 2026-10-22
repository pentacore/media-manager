<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Benchmark\BenchmarkRunContext;
use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkNeutralRejudgeStatus;
use App\Enums\QueueLane;
use App\Jobs\Middleware\LimitNeutralJudgementConcurrency;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkNeutralRejudge;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\Benchmark\BenchmarkContextStarter;
use App\Services\Benchmark\BenchmarkNeutralRejudgeLauncher;
use App\Services\Benchmark\BenchmarkScorer;
use App\Services\Benchmark\CaseOutcome;
use App\Support\UpstreamErrorText;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Throwable;

/**
 * Scores one stored benchmark result with a neutral re-judge's judge, inside
 * a benchmark context that does not bill the result's run. Checks the
 * re-judge first: a cancelled or capped one fails the judgement unscored,
 * and reaching the cap stops the whole re-judge. The judge's cost adds to
 * the re-judge's spend. Same wait and uniqueness model as RunBenchmarkCase.
 */
#[Queue(QueueLane::Ai)]
#[Timeout(280)]
#[Tries(0)]
#[MaxExceptions(1)]
#[FailOnTimeout]
#[UniqueFor(46800)]
final class RunNeutralJudgement implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Kept below the 13 h UniqueFor window. */
    private const int MAX_WAIT_HOURS = 12;

    private const int ERROR_LIMIT = 2000;

    public function __construct(public readonly int $judgementId) {}

    public function uniqueId(): string
    {
        return sprintf('neutral-judgement:%d', $this->judgementId);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new LimitNeutralJudgementConcurrency($this->judgementId)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::MAX_WAIT_HOURS);
    }

    public function handle(
        BenchmarkRunContext $benchmarkRunContext,
        BenchmarkContextStarter $benchmarkContextStarter,
        BenchmarkScorer $benchmarkScorer,
        AiBudgetGuard $aiBudgetGuard,
        BenchmarkNeutralRejudgeLauncher $benchmarkNeutralRejudgeLauncher,
    ): void {
        $benchmarkNeutralJudgement = BenchmarkNeutralJudgement::query()->with(['rejudge', 'result.run', 'result.benchmarkCase'])->find($this->judgementId);
        $benchmarkNeutralRejudge = $benchmarkNeutralJudgement?->rejudge;

        if (! $benchmarkNeutralJudgement instanceof BenchmarkNeutralJudgement || ! $benchmarkNeutralRejudge instanceof BenchmarkNeutralRejudge) {
            return;
        }

        if ($benchmarkNeutralRejudge->status !== BenchmarkNeutralRejudgeStatus::Running) {
            $this->failQueued($benchmarkNeutralJudgement, $benchmarkNeutralRejudge->status === BenchmarkNeutralRejudgeStatus::StoppedCostCap
                ? BenchmarkNeutralRejudgeLauncher::COST_CAP_REACHED
                : BenchmarkNeutralRejudgeLauncher::CANCELLED);

            return;
        }

        if ((float) $benchmarkNeutralRejudge->cost_usd >= (float) $benchmarkNeutralRejudge->cost_cap_usd) {
            $benchmarkNeutralRejudgeLauncher->stopAtCap($benchmarkNeutralRejudge->id);

            return;
        }

        if (! $this->claim($benchmarkNeutralJudgement)) {
            return;
        }

        $benchmarkResult = $benchmarkNeutralJudgement->result;
        $benchmarkCase = $benchmarkResult->benchmarkCase;

        if (! $benchmarkCase instanceof BenchmarkCase) {
            $benchmarkNeutralJudgement->update(['status' => BenchmarkJudgeStatus::Failed, 'detail' => ['error' => 'Case was deleted']]);
            $benchmarkNeutralRejudgeLauncher->settle($benchmarkNeutralRejudge->id);

            return;
        }

        try {
            $benchmarkContextStarter->start($benchmarkRunContext, $benchmarkResult->run, $benchmarkCase, $benchmarkResult->candidate_index, $benchmarkNeutralJudgement->judge, billsRun: false);
            $aiBudgetGuard->enforce();

            $scores = $benchmarkScorer->score($benchmarkCase, CaseOutcome::fromResult($benchmarkResult));
        } catch (Throwable $throwable) {
            report_unless($throwable instanceof AiBudgetExceededException, $throwable);
            $benchmarkNeutralJudgement->update([
                'status' => BenchmarkJudgeStatus::Failed,
                'detail' => ['error' => UpstreamErrorText::sanitize($throwable->getMessage(), self::ERROR_LIMIT)],
            ]);
            $benchmarkNeutralRejudgeLauncher->settle($benchmarkNeutralRejudge->id);

            return;
        } finally {
            $benchmarkRunContext->stop();
        }

        $costUsd = (float) $scores['judge_cost_usd'];

        $benchmarkNeutralJudgement->update([
            'status' => $scores['judge_status'],
            'score' => $scores['score'],
            'passed' => $scores['passed'],
            'detail' => $scores['detail'],
            'cost_usd' => $costUsd,
        ]);

        if ($costUsd > 0) {
            BenchmarkNeutralRejudge::query()->whereKey($benchmarkNeutralRejudge->id)->increment('cost_usd', $costUsd);
        }

        $benchmarkNeutralRejudgeLauncher->settle($benchmarkNeutralRejudge->id);
    }

    /**
     * The job itself failed (an exception escaped, it timed out or it waited
     * too long for a slot): fail its judgement and settle the re-judge.
     */
    public function failed(?Throwable $throwable): void
    {
        $rejudgeId = BenchmarkNeutralJudgement::query()->whereKey($this->judgementId)->value('benchmark_neutral_rejudge_id');

        BenchmarkNeutralJudgement::query()
            ->whereKey($this->judgementId)
            ->whereIn('status', [BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending])
            ->update(['status' => BenchmarkJudgeStatus::Failed, 'detail' => json_encode(['error' => BenchmarkNeutralRejudgeLauncher::JOB_FAILED], JSON_THROW_ON_ERROR)]);

        if ($rejudgeId !== null) {
            resolve(BenchmarkNeutralRejudgeLauncher::class)->settle((int) $rejudgeId);
        }
    }

    /**
     * Atomically move the judgement from queued (or a stale claim) to pending;
     * false when another job took it or it is no longer queued.
     */
    private function claim(BenchmarkNeutralJudgement $benchmarkNeutralJudgement): bool
    {
        return BenchmarkNeutralJudgement::query()
            ->whereKey($benchmarkNeutralJudgement->id)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where('status', BenchmarkJudgeStatus::Queued)
                ->orWhere(static fn (Builder $stale): Builder => $stale->stale()))
            ->update(['status' => BenchmarkJudgeStatus::Pending]) === 1;
    }

    private function failQueued(BenchmarkNeutralJudgement $benchmarkNeutralJudgement, string $reason): void
    {
        BenchmarkNeutralJudgement::query()
            ->whereKey($benchmarkNeutralJudgement->id)
            ->where('status', BenchmarkJudgeStatus::Queued)
            ->update(['status' => BenchmarkJudgeStatus::Failed, 'detail' => json_encode(['error' => $reason], JSON_THROW_ON_ERROR)]);
    }
}
