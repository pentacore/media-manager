<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkScoringType;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use Illuminate\Support\Collection;

/**
 * Aggregates a run's results into one comparison matrix per task: a row of
 * figures per candidate, the best candidate per metric and a cell per case ×
 * candidate for the drill-down. A candidate is best by score or pass rate
 * only when it was scored on as many cases as the most-covered candidate, so
 * a high average over fewer cases never wins.
 */
final readonly class BenchmarkMatrix
{
    /**
     * @return list<array{
     *     task: string,
     *     task_label: string,
     *     verdict: bool,
     *     candidates: list<array<string, mixed>>,
     *     max_scored: int,
     *     best: array{avg_score: int|null, pass_rate: int|null, cost_per_case: int|null, latency_p50: int|null},
     *     cases: list<array{case_id: int|null, case_name: string, cells: list<array{candidate_index: int, result_id: int, status: string, score: float|null, passed: bool|null, judge_status: string, judge_stale: bool, stale: bool}>}>
     * }>
     */
    public static function for(BenchmarkRun $benchmarkRun): array
    {
        $results = $benchmarkRun->results()
            ->select(['id', 'benchmark_case_id', 'case_name', 'case_task', 'candidate_index', 'status', 'cost_usd', 'judge_cost_usd', 'latency_ms', 'tool_misses', 'score', 'passed', 'judge_status', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->groupBy('case_task');

        $matrix = [];

        foreach ($results->sortBy(fn (Collection $group, string $task): int => self::taskOrder($task)) as $task => $taskResults) {
            $candidates = [];
            $verdict = AiTask::tryFrom((string) $task)?->benchmarkScoringType() === BenchmarkScoringType::Verdict;
            $judged = AiTask::tryFrom((string) $task)?->benchmarkScoringType() === BenchmarkScoringType::Judge;
            $verdictErrors = $verdict ? self::verdictErrors($benchmarkRun, (string) $task) : [];

            foreach ($benchmarkRun->candidates as $index => $candidate) {
                $candidates[] = self::candidateRow($benchmarkRun, $index, $candidate, $taskResults->where('candidate_index', $index), $judged, $verdict ? ($verdictErrors[$index] ?? ['missed' => 0, 'false_alarms' => 0]) : null);
            }

            $maxScored = $candidates === [] ? 0 : (int) max(array_column($candidates, 'scored'));
            $fullyScored = array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['scored'] === $maxScored));

            $matrix[] = [
                'task' => (string) $task,
                'task_label' => AiTask::tryFrom((string) $task)?->label() ?? (string) $task,
                'verdict' => $verdict,
                'candidates' => $candidates,
                'max_scored' => $maxScored,
                'best' => [
                    'avg_score' => self::best($fullyScored, 'avg_score', highest: true),
                    'pass_rate' => self::best($fullyScored, 'pass_rate', highest: true),
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
     * @param  bool  $judged  whether the task is judge-scored, the only kind a judge can grade its own candidate on
     * @param  array{missed: int, false_alarms: int}|null  $verdictErrors  null when the task is not verdict-scored
     * @return array<string, mixed>
     */
    private static function candidateRow(BenchmarkRun $benchmarkRun, int $index, array $candidate, Collection $results, bool $judged, ?array $verdictErrors): array
    {
        $judge = $benchmarkRun->judge;
        $reasoning = is_string($candidate['reasoning'] ?? null) ? $candidate['reasoning'] : null;

        return [
            'index' => $index,
            'provider' => $candidate['provider'] ?? null,
            'model' => $candidate['model'] ?? null,
            'reasoning' => $reasoning,
            'reasoning_label' => AiReasoningLevel::tryFrom((string) $reasoning)?->label() ?? AiReasoningLevel::ProviderDefault->label(),
            'self_judged' => $judged
                && is_array($judge)
                && ($judge['provider'] ?? null) === ($candidate['provider'] ?? null)
                && ($judge['model'] ?? null) === ($candidate['model'] ?? null),
            ...BenchmarkCandidateStats::of($results),
            'missed' => $verdictErrors['missed'] ?? null,
            'false_alarms' => $verdictErrors['false_alarms'] ?? null,
        ];
    }

    /**
     * Missed and false-alarm verdicts per candidate of one verdict-scored task.
     *
     * @return array<int, array{missed: int, false_alarms: int}>
     */
    private static function verdictErrors(BenchmarkRun $benchmarkRun, string $task): array
    {
        $counts = [];

        $rows = $benchmarkRun->results()
            ->where('case_task', $task)
            ->where('status', BenchmarkResultStatus::Ok)
            ->selectRaw("candidate_index, score_detail->>'error_kind' as error_kind, count(*) as total")
            ->groupByRaw("candidate_index, score_detail->>'error_kind'")
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->candidate_index] ??= ['missed' => 0, 'false_alarms' => 0];

            match ($row->error_kind) {
                'missed' => $counts[(int) $row->candidate_index]['missed'] += (int) $row->total,
                'false_alarm' => $counts[(int) $row->candidate_index]['false_alarms'] += (int) $row->total,
                default => null,
            };
        }

        return $counts;
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
     * @return list<array{case_id: int|null, case_name: string, cells: list<array{candidate_index: int, result_id: int, status: string, score: float|null, passed: bool|null, judge_status: string, judge_stale: bool, stale: bool}>}>
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
                    'judge_status' => $benchmarkResult->judge_status->value,
                    'judge_stale' => $benchmarkResult->isJudgeStale(),
                    'stale' => $benchmarkResult->isRunStale(),
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
