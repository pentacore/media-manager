<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use Illuminate\Support\Collection;

/**
 * Aggregates a run's results into one comparison matrix per task: a row of
 * figures per candidate, the best candidate per metric and a cell per case ×
 * candidate for the drill-down.
 */
final readonly class BenchmarkMatrix
{
    /**
     * @return list<array{
     *     task: string,
     *     task_label: string,
     *     candidates: list<array<string, mixed>>,
     *     best: array{avg_score: int|null, pass_rate: int|null, cost_per_case: int|null, latency_p50: int|null},
     *     cases: list<array{case_id: int|null, case_name: string, cells: list<array{candidate_index: int, result_id: int, status: string, score: float|null, passed: bool|null}>}>
     * }>
     */
    public static function for(BenchmarkRun $benchmarkRun): array
    {
        $results = $benchmarkRun->results()
            ->select(['id', 'benchmark_case_id', 'case_name', 'case_task', 'candidate_index', 'status', 'cost_usd', 'judge_cost_usd', 'latency_ms', 'tool_misses', 'score', 'passed'])
            ->orderBy('id')
            ->get()
            ->groupBy('case_task');

        $matrix = [];

        foreach ($results->sortBy(fn (Collection $group, string $task): int => self::taskOrder($task)) as $task => $taskResults) {
            $candidates = [];

            foreach ($benchmarkRun->candidates as $index => $candidate) {
                $candidates[] = self::candidateRow($benchmarkRun, $index, $candidate, $taskResults->where('candidate_index', $index));
            }

            $matrix[] = [
                'task' => (string) $task,
                'task_label' => AiTask::tryFrom((string) $task)?->label() ?? (string) $task,
                'candidates' => $candidates,
                'best' => [
                    'avg_score' => self::best($candidates, 'avg_score', highest: true),
                    'pass_rate' => self::best($candidates, 'pass_rate', highest: true),
                    'cost_per_case' => self::best($candidates, 'cost_per_case', highest: false),
                    'latency_p50' => self::best($candidates, 'latency_p50', highest: false),
                ],
                'cases' => self::caseRows($taskResults),
            ];
        }

        return $matrix;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  Collection<int, BenchmarkResult>  $results
     * @return array<string, mixed>
     */
    private static function candidateRow(BenchmarkRun $benchmarkRun, int $index, array $candidate, Collection $results): array
    {
        $ok = $results->where('status', BenchmarkResultStatus::Ok);
        $scores = $ok->whereNotNull('score')->pluck('score');
        $verdicts = $ok->whereNotNull('passed');
        $latencies = $ok->whereNotNull('latency_ms')->pluck('latency_ms')->map(fn (mixed $latency): int => (int) $latency)->sort()->values();
        $cost = $results->sum(fn (BenchmarkResult $benchmarkResult): float => (float) $benchmarkResult->cost_usd + (float) $benchmarkResult->judge_cost_usd);
        $judge = $benchmarkRun->judge;
        $reasoning = is_string($candidate['reasoning'] ?? null) ? $candidate['reasoning'] : null;

        return [
            'index' => $index,
            'provider' => $candidate['provider'] ?? null,
            'model' => $candidate['model'] ?? null,
            'reasoning' => $reasoning,
            'reasoning_label' => AiReasoningLevel::tryFrom((string) $reasoning)?->label() ?? AiReasoningLevel::ProviderDefault->label(),
            'self_judged' => is_array($judge)
                && ($judge['provider'] ?? null) === ($candidate['provider'] ?? null)
                && ($judge['model'] ?? null) === ($candidate['model'] ?? null),
            'results' => $results->count(),
            'ok' => $ok->count(),
            'failed' => $results->where('status', BenchmarkResultStatus::Failed)->count(),
            'skipped' => $results->where('status', BenchmarkResultStatus::Skipped)->count(),
            'avg_score' => $scores->isEmpty() ? null : round((float) $scores->avg(), 4),
            'pass_rate' => $verdicts->isEmpty() ? null : round($verdicts->where('passed', true)->count() / $verdicts->count(), 4),
            'cost_per_case' => $ok->isEmpty() ? null : round($cost / $ok->count(), 6),
            'latency_p50' => self::percentile($latencies, 50),
            'latency_p95' => self::percentile($latencies, 95),
            'tool_misses' => (int) $results->sum('tool_misses'),
        ];
    }

    /**
     * Nearest-rank percentile of an ascending list.
     *
     * @param  Collection<int, int>  $sorted
     */
    private static function percentile(Collection $sorted, int $percentile): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $rank = (int) ceil($percentile / 100 * $sorted->count());

        return $sorted->get(max($rank, 1) - 1);
    }

    /**
     * The index of the best candidate for a metric; the first wins a tie.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private static function best(array $candidates, string $metric, bool $highest): ?int
    {
        $bestIndex = null;
        $bestValue = null;

        foreach ($candidates as $candidate) {
            $value = $candidate[$metric];

            if ($value === null) {
                continue;
            }

            if ($bestValue === null || ($highest ? $value > $bestValue : $value < $bestValue)) {
                $bestValue = $value;
                $bestIndex = $candidate['index'];
            }
        }

        return $bestIndex;
    }

    /**
     * @param  Collection<int, BenchmarkResult>  $results
     * @return list<array{case_id: int|null, case_name: string, cells: list<array{candidate_index: int, result_id: int, status: string, score: float|null, passed: bool|null}>}>
     */
    private static function caseRows(Collection $results): array
    {
        return $results
            ->groupBy(fn (BenchmarkResult $benchmarkResult): string => $benchmarkResult->benchmark_case_id === null
                ? 'name:'.$benchmarkResult->case_name
                : 'id:'.$benchmarkResult->benchmark_case_id)
            ->map(fn (Collection $caseResults): array => [
                'case_id' => $caseResults->first()->benchmark_case_id,
                'case_name' => $caseResults->first()->case_name,
                'cells' => $caseResults->sortBy('candidate_index')->map(fn (BenchmarkResult $benchmarkResult): array => [
                    'candidate_index' => $benchmarkResult->candidate_index,
                    'result_id' => $benchmarkResult->id,
                    'status' => $benchmarkResult->status->value,
                    'score' => $benchmarkResult->score,
                    'passed' => $benchmarkResult->passed,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private static function taskOrder(string $task): int
    {
        $position = array_search(AiTask::tryFrom($task), AiTask::cases(), true);

        return $position === false ? PHP_INT_MAX : $position;
    }
}
