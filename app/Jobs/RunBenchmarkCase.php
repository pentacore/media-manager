<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\ResolvedSelection;
use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Enums\QueueLane;
use App\Jobs\Middleware\LimitBenchmarkConcurrency;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\ModelPriceLookup;
use App\Services\Benchmark\BenchmarkCaseRunner;
use App\Services\Benchmark\BenchmarkContextStarter;
use App\Services\Benchmark\BenchmarkRunSettler;
use App\Services\Benchmark\BenchmarkScorer;
use App\Services\Benchmark\CaseOutcome;
use App\Services\Benchmark\ScriptedTurnFailed;
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
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs one benchmark result: the case's agent on one candidate inside a
 * BenchmarkRunContext, then scores it, stores it, refreshes the run's cost
 * from its usage rows, stops the run at its cost cap and completes it after
 * the last result. A
 * failure of any step fails this result only; the run always settles.
 *
 * Judge-only mode re-scores a stored result whose judge failed, from its
 * stored outcome, without running the candidate again.
 *
 * Unlimited tries bounded by retryUntil(): the concurrency middleware
 * releases a job while the run's two slots are busy, and every release counts
 * as an attempt. One exception or a timeout fails the job (failed() then
 * fails the result). Unique per result and mode for the whole wait window, and
 * both modes claim their result atomically (pending → running, queued →
 * pending judge), so a result is never run or judged twice; a claim (or a
 * queued re-judge) untouched for BenchmarkResult::CLAIM_STALE_AFTER_SECONDS
 * lost its worker or its dispatch and may be taken over. The monthly hard
 * cap is enforced before every agent call.
 */
#[Queue(QueueLane::Ai)]
#[Timeout(280)]
#[Tries(0)]
#[MaxExceptions(1)]
#[FailOnTimeout]
#[UniqueFor(46800)]
final class RunBenchmarkCase implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Kept below the 13 h UniqueFor window. */
    private const int MAX_WAIT_HOURS = 12;

    private const int ERROR_LIMIT = 2000;

    public function __construct(public readonly int $resultId, public readonly bool $judgeOnly = false) {}

    public function uniqueId(): string
    {
        return sprintf('benchmark-result:%d:%s', $this->resultId, $this->judgeOnly ? 'judge' : 'run');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new LimitBenchmarkConcurrency($this->resultId)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::MAX_WAIT_HOURS);
    }

    public function handle(
        BenchmarkRunContext $benchmarkRunContext,
        BenchmarkCaseRunner $benchmarkCaseRunner,
        BenchmarkScorer $benchmarkScorer,
        ModelPriceLookup $modelPriceLookup,
        AiBudgetGuard $aiBudgetGuard,
        BenchmarkContextStarter $benchmarkContextStarter,
    ): void {
        $benchmarkResult = BenchmarkResult::query()->with(['run', 'benchmarkCase'])->find($this->resultId);

        if (! $benchmarkResult instanceof BenchmarkResult) {
            return;
        }

        if ($this->judgeOnly) {
            $this->rejudge($benchmarkResult, $benchmarkRunContext, $benchmarkScorer, $aiBudgetGuard, $benchmarkContextStarter);

            return;
        }

        $benchmarkRun = $benchmarkResult->run;

        if ($benchmarkRun->isTerminal()) {
            $this->settle($benchmarkRun->id);

            return;
        }

        if (! $benchmarkResult->status->isInProgress()) {
            return;
        }

        if ($this->costCapReached($benchmarkRun)) {
            $this->settle($benchmarkRun->id);

            return;
        }

        if (! $this->claim($benchmarkResult)) {
            return;
        }

        $benchmarkCase = $benchmarkResult->benchmarkCase;

        if (! $benchmarkCase instanceof BenchmarkCase) {
            $benchmarkResult->update(['status' => BenchmarkResultStatus::Failed, 'error' => 'Case was deleted']);
            $this->settle($benchmarkRun->id);

            return;
        }

        $this->runCase($benchmarkResult, $benchmarkRun, $benchmarkCase, $benchmarkRunContext, $benchmarkCaseRunner, $benchmarkScorer, $modelPriceLookup, $aiBudgetGuard, $benchmarkContextStarter);
        $this->settle($benchmarkRun->id);
    }

    /**
     * The job itself failed (an exception escaped, it timed out or it waited
     * too long for a slot): fail its pending or running result (the job is
     * unique per result, so a running one is this job's claim) and settle the run; a
     * judge-only job hands its queued or claimed result back to re-judging
     * and settles the run, so what its judge already spent reaches its cost.
     */
    public function failed(?Throwable $throwable): void
    {
        $benchmarkResult = BenchmarkResult::query()->find($this->resultId);

        if (! $benchmarkResult instanceof BenchmarkResult) {
            return;
        }

        if ($this->judgeOnly) {
            BenchmarkResult::query()
                ->whereKey($this->resultId)
                ->whereIn('judge_status', [BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending])
                ->update(['judge_status' => BenchmarkJudgeStatus::Failed]);
            $this->settle($benchmarkResult->benchmark_run_id);

            return;
        }

        if ($benchmarkResult->status->isInProgress()) {
            $benchmarkResult->update([
                'status' => BenchmarkResultStatus::Failed,
                'error' => UpstreamErrorText::sanitize($throwable?->getMessage() ?? 'The benchmark job failed.', self::ERROR_LIMIT),
            ]);
        }

        $this->settle($benchmarkResult->benchmark_run_id);
    }

    private function runCase(
        BenchmarkResult $benchmarkResult,
        BenchmarkRun $benchmarkRun,
        BenchmarkCase $benchmarkCase,
        BenchmarkRunContext $benchmarkRunContext,
        BenchmarkCaseRunner $benchmarkCaseRunner,
        BenchmarkScorer $benchmarkScorer,
        ModelPriceLookup $modelPriceLookup,
        AiBudgetGuard $aiBudgetGuard,
        BenchmarkContextStarter $benchmarkContextStarter,
    ): void {
        $caseOutcome = null;
        $costUsd = 0.0;
        $candidate = null;

        try {
            $candidate = BenchmarkContextStarter::selection($this->storedCandidate($benchmarkRun, $benchmarkResult->candidate_index));
            $this->ensureProviderReady($candidate->provider);
            $aiBudgetGuard->enforce();
            $benchmarkContextStarter->start($benchmarkRunContext, $benchmarkRun, $benchmarkCase, $benchmarkResult->candidate_index);

            $caseOutcome = $benchmarkCaseRunner->run($benchmarkCase);
            $costUsd = $this->candidateCost($caseOutcome, $candidate, $modelPriceLookup);
            $scores = $benchmarkScorer->score($benchmarkCase, $caseOutcome);
        } catch (Throwable $throwable) {
            if (! $caseOutcome instanceof CaseOutcome && $throwable instanceof ScriptedTurnFailed) {
                $caseOutcome = $throwable->partialOutcome;
                $costUsd = $candidate instanceof ResolvedSelection ? $this->candidateCost($caseOutcome, $candidate, $modelPriceLookup) : 0.0;
            }

            $this->reportUnexpected($throwable);
            $this->recordFailure($benchmarkResult, $throwable, $caseOutcome, $costUsd);

            return;
        } finally {
            $benchmarkRunContext->stop();
        }

        $benchmarkResult->update([
            ...$this->outcomeAttributes($caseOutcome, $costUsd),
            'status' => BenchmarkResultStatus::Ok,
            'error' => null,
            'score' => $scores['score'],
            'passed' => $scores['passed'],
            'score_detail' => $this->storedDetail($scores['detail'], $caseOutcome),
            'judge_status' => $scores['judge_status'],
            'judge_cost_usd' => $scores['judge_cost_usd'],
        ]);
    }

    /**
     * Fail the result; whatever the candidate already produced (and cost) is
     * kept for the drill-down. The run's cost comes from its usage rows, so a
     * failure's spend counts towards the cap either way.
     */
    private function recordFailure(BenchmarkResult $benchmarkResult, Throwable $throwable, ?CaseOutcome $caseOutcome, float $costUsd): void
    {
        $benchmarkResult->update([
            ...($caseOutcome instanceof CaseOutcome ? [
                ...$this->outcomeAttributes($caseOutcome, $costUsd),
                'score_detail' => $this->storedDetail([], $caseOutcome),
            ] : []),
            'status' => BenchmarkResultStatus::Failed,
            'error' => UpstreamErrorText::sanitize($throwable->getMessage(), self::ERROR_LIMIT),
        ]);
    }

    /**
     * Re-score a result queued for re-judging, under the run's judge, from the
     * stored outcome. The case must still exist (its prompt and rubric feed
     * the judge); without it the result goes back to `failed`.
     */
    private function rejudge(BenchmarkResult $benchmarkResult, BenchmarkRunContext $benchmarkRunContext, BenchmarkScorer $benchmarkScorer, AiBudgetGuard $aiBudgetGuard, BenchmarkContextStarter $benchmarkContextStarter): void
    {
        if (! $this->claimForJudging($benchmarkResult)) {
            return;
        }

        $caseOutcome = CaseOutcome::fromResult($benchmarkResult);
        $benchmarkCase = $benchmarkResult->benchmarkCase;

        if (! $benchmarkCase instanceof BenchmarkCase) {
            $benchmarkResult->update([
                'judge_status' => BenchmarkJudgeStatus::Failed,
                'score_detail' => ['error' => 'Case was deleted', 'tool_log' => $caseOutcome->toolLog],
            ]);

            return;
        }

        try {
            $benchmarkContextStarter->start($benchmarkRunContext, $benchmarkResult->run, $benchmarkCase, $benchmarkResult->candidate_index);
            $aiBudgetGuard->enforce();

            $scores = $benchmarkScorer->score($benchmarkCase, $caseOutcome);
        } catch (Throwable $throwable) {
            $this->reportUnexpected($throwable);
            $benchmarkResult->update([
                'judge_status' => BenchmarkJudgeStatus::Failed,
                'score_detail' => $this->storedDetail(['error' => UpstreamErrorText::sanitize($throwable->getMessage(), self::ERROR_LIMIT)], $caseOutcome),
            ]);
            $this->settle($benchmarkResult->benchmark_run_id);

            return;
        } finally {
            $benchmarkRunContext->stop();
        }

        $benchmarkResult->update([
            'score' => $scores['score'],
            'passed' => $scores['passed'],
            'score_detail' => $this->storedDetail($scores['detail'], $caseOutcome),
            'judge_status' => $scores['judge_status'],
            'judge_cost_usd' => $scores['judge_cost_usd'],
        ]);
        $this->settle($benchmarkResult->benchmark_run_id);
    }

    /**
     * Atomically move the result to `running` (pending, or running under a
     * stale claim whose worker died) while its run is still going, under a
     * lock on the run so a delete can't slip between the check and the claim.
     * False when another job claimed it first or the run ended.
     */
    private function claim(BenchmarkResult $benchmarkResult): bool
    {
        $claimed = DB::transaction(function () use ($benchmarkResult): bool {
            $benchmarkRun = BenchmarkRun::query()->lockForUpdate()->find($benchmarkResult->benchmark_run_id);

            if (! $benchmarkRun instanceof BenchmarkRun || $benchmarkRun->isTerminal()) {
                return false;
            }

            return BenchmarkResult::query()
                ->whereKey($benchmarkResult->id)
                ->claimable()
                ->update(['status' => BenchmarkResultStatus::Running]) === 1;
        });

        if ($claimed) {
            $benchmarkResult->status = BenchmarkResultStatus::Running;
            $benchmarkResult->syncOriginalAttribute('status');
        }

        return $claimed;
    }

    /**
     * Atomically move a result queued for re-judging (or whose re-judge went
     * stale: a dropped dispatch, a judge-only worker that died) to the
     * transient `pending` judge status, under a lock on the run so a delete
     * can't slip between its in-flight check and this claim; false when
     * another live job claimed it first or it is not queued.
     */
    private function claimForJudging(BenchmarkResult $benchmarkResult): bool
    {
        $claimed = DB::transaction(function () use ($benchmarkResult): bool {
            BenchmarkRun::query()->lockForUpdate()->find($benchmarkResult->benchmark_run_id);

            return BenchmarkResult::query()
                ->whereKey($benchmarkResult->id)
                ->where('status', BenchmarkResultStatus::Ok)
                ->where(fn (Builder $builder): Builder => $builder
                    ->where('judge_status', BenchmarkJudgeStatus::Queued)
                    ->orWhere(fn (Builder $stale): Builder => $stale->judgeStale()))
                ->update(['judge_status' => BenchmarkJudgeStatus::Pending]) === 1;
        });

        if (! $claimed) {
            return false;
        }

        $benchmarkResult->judge_status = BenchmarkJudgeStatus::Pending;
        $benchmarkResult->syncOriginalAttribute('judge_status');

        return true;
    }

    /**
     * A refused hard cap is an expected outcome, stored on the result; every
     * other failure is reported.
     */
    private function reportUnexpected(Throwable $throwable): void
    {
        report_unless($throwable instanceof AiBudgetExceededException || $throwable->getPrevious() instanceof AiBudgetExceededException, $throwable);
    }

    /**
     * The result's score detail: the judge's detail, the tool log and, for a
     * scripted case, the candidate transcript.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function storedDetail(array $detail, CaseOutcome $caseOutcome): array
    {
        return [
            ...$detail,
            'tool_log' => $caseOutcome->toolLog,
            ...($caseOutcome->transcript === [] ? [] : ['transcript' => $caseOutcome->transcript]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedCandidate(BenchmarkRun $benchmarkRun, int $candidateIndex): array
    {
        $candidate = $benchmarkRun->candidates[$candidateIndex] ?? null;

        throw_unless(is_array($candidate), RuntimeException::class, sprintf('The run has no candidate #%d.', $candidateIndex));

        return $candidate;
    }

    /**
     * Fail before any agent work when the candidate cannot run, with a reason
     * an admin can act on (Ollama needs no key).
     */
    private function ensureProviderReady(string $provider): void
    {
        $config = $provider === '' ? null : config(sprintf('ai.providers.%s', $provider));

        throw_unless(is_array($config), RuntimeException::class, sprintf('The %s provider is not configured.', $provider === '' ? 'candidate' : $provider));
        throw_if($provider !== 'ollama' && blank($config['key'] ?? null), RuntimeException::class, sprintf('The %s provider has no API key configured.', $provider));
    }

    /**
     * The candidate's USD cost at its catalog price; 0 when unpriced. The
     * outcome's token counts are inclusive, so cache and reasoning tokens are
     * priced at the plain input/output rates (an accepted approximation).
     */
    private function candidateCost(CaseOutcome $caseOutcome, ResolvedSelection $candidate, ModelPriceLookup $modelPriceLookup): float
    {
        $snapshot = $modelPriceLookup->snapshotFor($candidate->provider, $candidate->model, false);

        return $snapshot === null ? 0.0 : $modelPriceLookup->costOf([
            'prompt_tokens' => $caseOutcome->promptTokens,
            'completion_tokens' => $caseOutcome->completionTokens,
        ], $snapshot);
    }

    /**
     * @return array{output: string, intended_actions: list<array{tool: string, arguments: array<string, mixed>}>, prompt_tokens: int, completion_tokens: int, cost_usd: float, latency_ms: int, tool_calls: int, tool_misses: int}
     */
    private function outcomeAttributes(CaseOutcome $caseOutcome, float $costUsd): array
    {
        return [
            'output' => $caseOutcome->output,
            'intended_actions' => $caseOutcome->intendedActions,
            'prompt_tokens' => $caseOutcome->promptTokens,
            'completion_tokens' => $caseOutcome->completionTokens,
            'cost_usd' => $costUsd,
            'latency_ms' => $caseOutcome->latencyMs,
            'tool_calls' => $caseOutcome->toolCalls,
            'tool_misses' => $caseOutcome->toolMisses,
        ];
    }

    private function costCapReached(BenchmarkRun $benchmarkRun): bool
    {
        return $benchmarkRun->cost_cap_usd !== null && (float) $benchmarkRun->cost_usd >= (float) $benchmarkRun->cost_cap_usd;
    }

    private function settle(int $runId): void
    {
        resolve(BenchmarkRunSettler::class)->settle($runId);
    }
}
