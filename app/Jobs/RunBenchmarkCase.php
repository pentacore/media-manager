<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Benchmark\FixtureBook;
use App\Ai\ResolvedSelection;
use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Enums\QueueLane;
use App\Jobs\Middleware\LimitBenchmarkConcurrency;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\ModelPriceLookup;
use App\Services\Benchmark\BenchmarkCaseRunner;
use App\Services\Benchmark\BenchmarkScorer;
use App\Services\Benchmark\CaseOutcome;
use App\Support\UpstreamErrorText;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;
use Throwable;

/**
 * Runs one benchmark result: the case's agent on one candidate inside a
 * BenchmarkRunContext, then scores it, stores it, adds its cost to the run,
 * stops the run at its cost cap and completes it after the last result. A
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
 * pending judge), so a result is never run or judged twice. The monthly hard
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
    ): void {
        $benchmarkResult = BenchmarkResult::query()->with(['run', 'benchmarkCase'])->find($this->resultId);

        if (! $benchmarkResult instanceof BenchmarkResult) {
            return;
        }

        if ($this->judgeOnly) {
            $this->rejudge($benchmarkResult, $benchmarkRunContext, $benchmarkScorer, $aiBudgetGuard);

            return;
        }

        $benchmarkRun = $benchmarkResult->run;

        if ($benchmarkRun->isTerminal() || ! $benchmarkResult->status->isInProgress()) {
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

        $this->runCase($benchmarkResult, $benchmarkRun, $benchmarkCase, $benchmarkRunContext, $benchmarkCaseRunner, $benchmarkScorer, $modelPriceLookup, $aiBudgetGuard);
        $this->settle($benchmarkRun->id);
    }

    /**
     * The job itself failed (an exception escaped, it timed out or it waited
     * too long for a slot): fail its pending or running result (the job is
     * unique per result, so a running one is this job's claim) and settle the run; a
     * judge-only job hands its queued or claimed result back to re-judging.
     */
    public function failed(?Throwable $throwable): void
    {
        if ($this->judgeOnly) {
            BenchmarkResult::query()
                ->whereKey($this->resultId)
                ->whereIn('judge_status', [BenchmarkScorer::JUDGE_QUEUED, BenchmarkScorer::JUDGE_PENDING])
                ->update(['judge_status' => BenchmarkScorer::JUDGE_FAILED]);

            return;
        }

        $benchmarkResult = BenchmarkResult::query()->find($this->resultId);

        if (! $benchmarkResult instanceof BenchmarkResult) {
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
    ): void {
        $caseOutcome = null;
        $costUsd = 0.0;

        try {
            $candidate = $this->candidate($benchmarkRun, $benchmarkResult->candidate_index);
            $this->ensureProviderReady($candidate->provider);
            $aiBudgetGuard->enforce();
            $this->startContext($benchmarkRunContext, $benchmarkRun, $benchmarkCase, $candidate);

            $caseOutcome = $benchmarkCaseRunner->run($benchmarkCase);
            $costUsd = $this->candidateCost($caseOutcome, $candidate, $modelPriceLookup);
            $scores = $benchmarkScorer->score($benchmarkCase, $caseOutcome);
        } catch (Throwable $throwable) {
            $this->reportUnexpected($throwable);
            $this->recordFailure($benchmarkResult, $throwable, $caseOutcome, $costUsd);

            return;
        } finally {
            $benchmarkRunContext->stop();
        }

        DB::transaction(function () use ($benchmarkResult, $caseOutcome, $costUsd, $scores): void {
            $benchmarkResult->update([
                ...$this->outcomeAttributes($caseOutcome, $costUsd),
                'status' => BenchmarkResultStatus::Ok,
                'error' => null,
                'score' => $scores['score'],
                'passed' => $scores['passed'],
                'score_detail' => [...$scores['detail'], 'tool_log' => $caseOutcome->toolLog],
                'judge_status' => $scores['judge_status'],
                'judge_cost_usd' => $scores['judge_cost_usd'],
            ]);

            $this->addRunCost($benchmarkResult->benchmark_run_id, $costUsd + $scores['judge_cost_usd']);
        });
    }

    /**
     * Fail the result; whatever the candidate already produced (and cost) is
     * kept, so the run total stays honest for the cost cap.
     */
    private function recordFailure(BenchmarkResult $benchmarkResult, Throwable $throwable, ?CaseOutcome $caseOutcome, float $costUsd): void
    {
        DB::transaction(function () use ($benchmarkResult, $throwable, $caseOutcome, $costUsd): void {
            $benchmarkResult->update([
                ...($caseOutcome instanceof CaseOutcome ? [
                    ...$this->outcomeAttributes($caseOutcome, $costUsd),
                    'score_detail' => ['tool_log' => $caseOutcome->toolLog],
                ] : []),
                'status' => BenchmarkResultStatus::Failed,
                'error' => UpstreamErrorText::sanitize($throwable->getMessage(), self::ERROR_LIMIT),
            ]);

            if ($costUsd > 0) {
                $this->addRunCost($benchmarkResult->benchmark_run_id, $costUsd);
            }
        });
    }

    /**
     * Re-score a result queued for re-judging, under the run's judge, from the
     * stored outcome. The case must still exist (its prompt and rubric feed
     * the judge); without it the result goes back to `failed`.
     */
    private function rejudge(BenchmarkResult $benchmarkResult, BenchmarkRunContext $benchmarkRunContext, BenchmarkScorer $benchmarkScorer, AiBudgetGuard $aiBudgetGuard): void
    {
        if (! $this->claimForJudging($benchmarkResult)) {
            return;
        }

        $caseOutcome = $this->storedOutcome($benchmarkResult);
        $benchmarkCase = $benchmarkResult->benchmarkCase;

        if (! $benchmarkCase instanceof BenchmarkCase) {
            $benchmarkResult->update([
                'judge_status' => BenchmarkScorer::JUDGE_FAILED,
                'score_detail' => ['error' => 'Case was deleted', 'tool_log' => $caseOutcome->toolLog],
            ]);

            return;
        }

        try {
            $this->startContext(
                $benchmarkRunContext,
                $benchmarkResult->run,
                $benchmarkCase,
                $this->candidate($benchmarkResult->run, $benchmarkResult->candidate_index),
            );
            $aiBudgetGuard->enforce();

            $scores = $benchmarkScorer->score($benchmarkCase, $caseOutcome);
        } catch (Throwable $throwable) {
            $this->reportUnexpected($throwable);
            $benchmarkResult->update([
                'judge_status' => BenchmarkScorer::JUDGE_FAILED,
                'score_detail' => [
                    'error' => UpstreamErrorText::sanitize($throwable->getMessage(), self::ERROR_LIMIT),
                    'tool_log' => $caseOutcome->toolLog,
                ],
            ]);

            return;
        } finally {
            $benchmarkRunContext->stop();
        }

        DB::transaction(function () use ($benchmarkResult, $caseOutcome, $scores): void {
            $benchmarkResult->update([
                'score' => $scores['score'],
                'passed' => $scores['passed'],
                'score_detail' => [...$scores['detail'], 'tool_log' => $caseOutcome->toolLog],
                'judge_status' => $scores['judge_status'],
                'judge_cost_usd' => $scores['judge_cost_usd'],
            ]);

            $this->addRunCost($benchmarkResult->benchmark_run_id, $scores['judge_cost_usd']);
        });
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
     * Atomically move a result queued for re-judging from `queued` to the
     * transient `pending` judge status, under a lock on the run so a delete
     * can't slip between its in-flight check and this claim; false when
     * another job claimed it first or it is not queued.
     */
    private function claimForJudging(BenchmarkResult $benchmarkResult): bool
    {
        $claimed = DB::transaction(function () use ($benchmarkResult): bool {
            BenchmarkRun::query()->lockForUpdate()->find($benchmarkResult->benchmark_run_id);

            return BenchmarkResult::query()
                ->whereKey($benchmarkResult->id)
                ->where('status', BenchmarkResultStatus::Ok)
                ->where('judge_status', BenchmarkScorer::JUDGE_QUEUED)
                ->update(['judge_status' => BenchmarkScorer::JUDGE_PENDING]) === 1;
        });

        if (! $claimed) {
            return false;
        }

        $benchmarkResult->judge_status = BenchmarkScorer::JUDGE_PENDING;
        $benchmarkResult->syncOriginalAttribute('judge_status');

        return true;
    }

    /**
     * A refused hard cap is an expected outcome, stored on the result; every
     * other failure is reported.
     */
    private function reportUnexpected(Throwable $throwable): void
    {
        report_unless($throwable instanceof AiBudgetExceededException, $throwable);
    }

    private function startContext(BenchmarkRunContext $benchmarkRunContext, BenchmarkRun $benchmarkRun, BenchmarkCase $benchmarkCase, ResolvedSelection $candidate): void
    {
        $benchmarkRunContext->start(
            $benchmarkRun->id,
            $benchmarkCase->task,
            $candidate,
            is_array($benchmarkRun->judge) ? $this->selection($benchmarkRun->judge) : null,
            new FixtureBook($benchmarkCase->fixtures),
            $this->history($benchmarkCase),
            AiMode::tryFrom((string) ($benchmarkCase->input['mode'] ?? '')),
        );
    }

    private function candidate(BenchmarkRun $benchmarkRun, int $candidateIndex): ResolvedSelection
    {
        $candidate = $benchmarkRun->candidates[$candidateIndex] ?? null;

        throw_unless(is_array($candidate), RuntimeException::class, sprintf('The run has no candidate #%d.', $candidateIndex));

        return $this->selection($candidate);
    }

    /**
     * @param  array<string, mixed>  $selection  a stored `{provider, model, reasoning}`
     */
    private function selection(array $selection): ResolvedSelection
    {
        return new ResolvedSelection(
            is_string($selection['provider'] ?? null) ? $selection['provider'] : '',
            is_string($selection['model'] ?? null) ? $selection['model'] : '',
            AiReasoningLevel::tryFrom(is_string($selection['reasoning'] ?? null) ? $selection['reasoning'] : '') ?? AiReasoningLevel::ProviderDefault,
        );
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
     * The case's chat history as SDK messages (other roles are skipped).
     *
     * @return list<Message>
     */
    private function history(BenchmarkCase $benchmarkCase): array
    {
        $messages = [];

        foreach ((array) ($benchmarkCase->input['history'] ?? []) as $turn) {
            if (! is_array($turn) || ! is_string($turn['content'] ?? null)) {
                continue;
            }

            $message = match ($turn['role'] ?? null) {
                'user' => new UserMessage($turn['content']),
                'assistant' => new AssistantMessage($turn['content']),
                default => null,
            };

            if ($message instanceof Message) {
                $messages[] = $message;
            }
        }

        return $messages;
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

    /**
     * The outcome as stored on the result, normalised so a malformed stored
     * entry cannot break the judge prompt.
     */
    private function storedOutcome(BenchmarkResult $benchmarkResult): CaseOutcome
    {
        $storedLog = $benchmarkResult->score_detail['tool_log'] ?? [];
        $toolLog = [];

        foreach (is_array($storedLog) ? $storedLog : [] as $call) {
            if (! is_array($call) || ! is_string($call['tool'] ?? null)) {
                continue;
            }

            $toolLog[] = [
                'tool' => $call['tool'],
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                'result' => is_string($call['result'] ?? null) ? $call['result'] : null,
                'hit' => (bool) ($call['hit'] ?? false),
                ...(($call['write'] ?? false) === true ? ['write' => true] : []),
            ];
        }

        $intendedActions = [];

        foreach ($benchmarkResult->intended_actions as $action) {
            if (! is_string($action['tool'] ?? null)) {
                continue;
            }

            $intendedActions[] = ['tool' => $action['tool'], 'arguments' => is_array($action['arguments'] ?? null) ? $action['arguments'] : []];
        }

        return new CaseOutcome(
            output: $benchmarkResult->output ?? '',
            intendedActions: $intendedActions,
            toolLog: $toolLog,
            toolCalls: $benchmarkResult->tool_calls,
            toolMisses: $benchmarkResult->tool_misses,
            promptTokens: $benchmarkResult->prompt_tokens,
            completionTokens: $benchmarkResult->completion_tokens,
            latencyMs: $benchmarkResult->latency_ms ?? 0,
            invocationId: null,
        );
    }

    private function addRunCost(int $runId, float $usd): void
    {
        if ($usd <= 0) {
            return;
        }

        BenchmarkRun::query()->whereKey($runId)->increment('cost_usd', round($usd, 6));
    }

    private function costCapReached(BenchmarkRun $benchmarkRun): bool
    {
        return $benchmarkRun->cost_cap_usd !== null && (float) $benchmarkRun->cost_usd >= (float) $benchmarkRun->cost_cap_usd;
    }

    /**
     * Under a lock on the run: complete it once no result is pending or
     * running, or stop it at its cost cap (skipping every pending result; a
     * running one finishes and keeps its result) once the total reached the
     * cap. A terminal run is left alone.
     */
    private function settle(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $benchmarkRun = BenchmarkRun::query()->lockForUpdate()->find($runId);

            if (! $benchmarkRun instanceof BenchmarkRun || $benchmarkRun->isTerminal()) {
                return;
            }

            $unfinished = $benchmarkRun->results()->whereIn('status', [BenchmarkResultStatus::Pending, BenchmarkResultStatus::Running]);

            if (! $unfinished->exists()) {
                $benchmarkRun->update(['status' => BenchmarkRunStatus::Completed, 'finished_at' => now()]);

                return;
            }

            if ($this->costCapReached($benchmarkRun)) {
                $benchmarkRun->results()->where('status', BenchmarkResultStatus::Pending)->update(['status' => BenchmarkResultStatus::Skipped]);
                $benchmarkRun->update(['status' => BenchmarkRunStatus::StoppedCostCap, 'finished_at' => now()]);
            }
        });
    }
}
