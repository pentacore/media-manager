<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;

/**
 * Compares a finished scheduled run with the schedule's previous comparable
 * run, per candidate: the latest earlier Completed or StoppedCostCap run of
 * the same schedule judged by the same model that also ran this candidate.
 * Only cases both runs finished are compared. A candidate regressed when its
 * pass rate fell, or its error rate rose, by more than the schedule's
 * pass-rate threshold, or its average score fell by more than the score
 * threshold (both in percentage points).
 */
final readonly class BenchmarkRegressionDetector
{
    /**
     * @return array{baseline_run_id: int|null, regressions: list<array{candidate: array{provider: string, model: string, reasoning: string|null}, paired_cases: int, metrics: list<array{metric: string, before: float, after: float}>}>}
     */
    public function detect(BenchmarkRun $benchmarkRun): array
    {
        $benchmarkSchedule = $benchmarkRun->schedule;

        if ($benchmarkSchedule === null) {
            return ['baseline_run_id' => null, 'regressions' => []];
        }

        $earlierRuns = BenchmarkRun::query()
            ->where('benchmark_schedule_id', $benchmarkSchedule->id)
            ->where('id', '<', $benchmarkRun->id)
            ->whereIn('status', [BenchmarkRunStatus::Completed, BenchmarkRunStatus::StoppedCostCap])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (BenchmarkRun $earlier): bool => $this->sameSelection($earlier->judge, $benchmarkRun->judge));

        $passThreshold = (float) $benchmarkSchedule->pass_rate_threshold / 100;
        $scoreThreshold = (float) $benchmarkSchedule->score_threshold / 100;
        $baselineRunId = null;
        $regressions = [];

        foreach ($benchmarkRun->candidates as $index => $candidate) {
            $baseline = null;
            $baselineIndex = null;

            foreach ($earlierRuns as $earlierRun) {
                $baselineIndex = $this->candidateIndex($earlierRun, $candidate);

                if ($baselineIndex !== null) {
                    $baseline = $earlierRun;

                    break;
                }
            }

            if (! $baseline instanceof BenchmarkRun || $baselineIndex === null) {
                continue;
            }

            $after = $this->outcomes($benchmarkRun, $index);
            $before = $this->outcomes($baseline, $baselineIndex);
            $paired = array_keys(array_intersect_key($after, $before));

            if ($paired === []) {
                continue;
            }

            $baselineRunId ??= $baseline->id;
            $beforeFigures = $this->figures(array_intersect_key($before, array_flip($paired)));
            $afterFigures = $this->figures(array_intersect_key($after, array_flip($paired)));
            $metrics = [];

            if ($this->crossed($beforeFigures['pass_rate'], $afterFigures['pass_rate'], $passThreshold, falling: true)) {
                $metrics[] = ['metric' => 'pass_rate', 'before' => $beforeFigures['pass_rate'], 'after' => $afterFigures['pass_rate']];
            }

            if ($this->crossed($beforeFigures['error_rate'], $afterFigures['error_rate'], $passThreshold, falling: false)) {
                $metrics[] = ['metric' => 'error_rate', 'before' => $beforeFigures['error_rate'], 'after' => $afterFigures['error_rate']];
            }

            if ($this->crossed($beforeFigures['avg_score'], $afterFigures['avg_score'], $scoreThreshold, falling: true)) {
                $metrics[] = ['metric' => 'avg_score', 'before' => $beforeFigures['avg_score'], 'after' => $afterFigures['avg_score']];
            }

            if ($metrics !== []) {
                $regressions[] = [
                    'candidate' => ['provider' => (string) $candidate['provider'], 'model' => (string) $candidate['model'], 'reasoning' => $candidate['reasoning'] ?? null],
                    'paired_cases' => count($paired),
                    'metrics' => $metrics,
                ];
            }
        }

        return ['baseline_run_id' => $baselineRunId, 'regressions' => $regressions];
    }

    /**
     * Finished results of one candidate keyed by case id.
     *
     * @return array<int, BenchmarkResult>
     */
    private function outcomes(BenchmarkRun $benchmarkRun, int $candidateIndex): array
    {
        return $benchmarkRun->results()
            ->where('candidate_index', $candidateIndex)
            ->whereNotNull('benchmark_case_id')
            ->whereIn('status', [BenchmarkResultStatus::Ok, BenchmarkResultStatus::Failed])
            ->get(['benchmark_case_id', 'status', 'score', 'passed'])
            ->keyBy('benchmark_case_id')
            ->all();
    }

    /**
     * @param  array<int, BenchmarkResult>  $results
     * @return array{error_rate: float, pass_rate: float|null, avg_score: float|null}
     */
    private function figures(array $results): array
    {
        $all = collect($results);
        $ok = $all->where('status', BenchmarkResultStatus::Ok);
        $verdicts = $ok->whereNotNull('passed');
        $scores = $ok->whereNotNull('score')->pluck('score');

        return [
            'error_rate' => round($all->where('status', BenchmarkResultStatus::Failed)->count() / max(1, $all->count()), 4),
            'pass_rate' => $verdicts->isEmpty() ? null : round($verdicts->where('passed', true)->count() / $verdicts->count(), 4),
            'avg_score' => $scores->isEmpty() ? null : round((float) $scores->avg(), 4),
        ];
    }

    private function crossed(?float $before, ?float $after, float $threshold, bool $falling): bool
    {
        if ($before === null || $after === null) {
            return false;
        }

        return round($falling ? $before - $after : $after - $before, 4) > round($threshold, 4);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateIndex(BenchmarkRun $benchmarkRun, array $candidate): ?int
    {
        foreach ($benchmarkRun->candidates as $index => $earlier) {
            if ($this->sameSelection($earlier, $candidate)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    private function sameSelection(?array $a, ?array $b): bool
    {
        $normalise = static fn (?array $selection): ?array => $selection === null ? null : [
            (string) ($selection['provider'] ?? ''),
            (string) ($selection['model'] ?? ''),
            (string) ($selection['reasoning'] ?? 'provider_default'),
        ];

        return $normalise($a) === $normalise($b);
    }
}
