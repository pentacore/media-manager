<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * The result drawer's payload for a benchmark result.
 */
final readonly class BenchmarkResultDetails
{
    /**
     * Every candidate's result for the clicked result's case, with the case's
     * scoring and prompt when the case still exists. With a neutral judge
     * key (a comparison's neutral score mode), judge-scored results show that
     * judge's score, verdict and rationale instead of their own, or none while
     * it has not scored them, like the comparison grid does.
     *
     * @return array<string, mixed>
     */
    public static function for(BenchmarkResult $clicked, ?string $neutralJudgeKey = null): array
    {
        $siblings = BenchmarkResult::query()
            ->where('benchmark_run_id', $clicked->benchmark_run_id)
            ->where('case_task', $clicked->case_task)
            ->when(
                $clicked->benchmark_case_id === null,
                static fn ($query) => $query->whereNull('benchmark_case_id')->where('case_name', $clicked->case_name),
                static fn ($query) => $query->where('benchmark_case_id', $clicked->benchmark_case_id),
            )
            ->orderBy('candidate_index')
            ->get();

        $benchmarkCase = $clicked->benchmarkCase;
        $neutral = $neutralJudgeKey === null || $benchmarkCase?->isJudged() !== true ? null : BenchmarkNeutralJudgement::query()
            ->whereIn('benchmark_result_id', $siblings->pluck('id'))
            ->where('judge_key', $neutralJudgeKey)
            ->where('status', BenchmarkJudgeStatus::Ok)
            ->get()
            ->keyBy('benchmark_result_id');
        $neutralJudge = $neutralJudgeKey === null ? null : BenchmarkNeutralJudgement::query()->where('judge_key', $neutralJudgeKey)->value('judge');

        return [
            'case_id' => $clicked->benchmark_case_id,
            'case_name' => $clicked->case_name,
            'case_task' => $clicked->case_task,
            'scoring' => $benchmarkCase?->scoring,
            'prompt' => $benchmarkCase?->input['prompt'] ?? null,
            'neutral_judge_label' => is_array($neutralJudge) ? BenchmarkComparison::candidateLabel($neutralJudge) : null,
            'results' => $siblings->map(static fn (BenchmarkResult $benchmarkResult): array => self::withNeutral([
                'result_id' => $benchmarkResult->id,
                'candidate_index' => $benchmarkResult->candidate_index,
                'status' => $benchmarkResult->status->value,
                'output' => $benchmarkResult->output,
                'intended_actions' => $benchmarkResult->intended_actions,
                'score' => $benchmarkResult->score,
                'passed' => $benchmarkResult->passed,
                'judge_status' => $benchmarkResult->judge_status->value,
                'judge_stale' => $benchmarkResult->isJudgeStale(),
                'stale' => $benchmarkResult->isRunStale(),
                'score_detail' => collect($benchmarkResult->score_detail ?? [])->except(['tool_log', 'transcript'])->all(),
                'error' => $benchmarkResult->error,
                'tool_log' => $benchmarkResult->score_detail['tool_log'] ?? [],
                'transcript' => CaseOutcome::fromResult($benchmarkResult)->transcript,
                'latency_ms' => $benchmarkResult->latency_ms,
                'cost_usd' => (float) $benchmarkResult->cost_usd + (float) $benchmarkResult->judge_cost_usd,
            ], $benchmarkResult, $neutral))->all(),
        ];
    }

    /**
     * A judge-scored OK result's drawer row with the neutral judge's score,
     * verdict and rationale; any other row unchanged.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<int, BenchmarkNeutralJudgement>|null  $neutral
     * @return array<string, mixed>
     */
    private static function withNeutral(array $row, BenchmarkResult $benchmarkResult, ?Collection $neutral): array
    {
        if (! $neutral instanceof Collection || $benchmarkResult->status !== BenchmarkResultStatus::Ok) {
            return $row;
        }

        $judgement = $neutral->get($benchmarkResult->id);

        return [
            ...$row,
            'score' => $judgement?->score,
            'passed' => $judgement?->passed,
            'judge_status' => ($judgement->status ?? BenchmarkJudgeStatus::None)->value,
            'judge_stale' => false,
            'score_detail' => $judgement->detail ?? [],
        ];
    }
}
