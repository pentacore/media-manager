<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Models\BenchmarkResult;

/**
 * The result drawer's payload for a benchmark result.
 */
final readonly class BenchmarkResultDetails
{
    /**
     * Every candidate's result for the clicked result's case, with the case's
     * scoring and prompt when the case still exists.
     *
     * @return array<string, mixed>
     */
    public static function for(BenchmarkResult $clicked): array
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

        return [
            'case_id' => $clicked->benchmark_case_id,
            'case_name' => $clicked->case_name,
            'case_task' => $clicked->case_task,
            'scoring' => $benchmarkCase?->scoring,
            'prompt' => $benchmarkCase?->input['prompt'] ?? null,
            'results' => $siblings->map(static fn (BenchmarkResult $benchmarkResult): array => [
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
            ])->all(),
        ];
    }
}
