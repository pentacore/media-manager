<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkResult;
use Illuminate\Support\Collection;

/**
 * The figures one candidate earned over a set of its results: how many
 * finished, failed or were skipped, the average score and pass rate of the
 * scored ones, the cost per finished case (candidate plus judge) and the
 * latency percentiles. Shared by the run matrix and the cross-run comparison
 * so both pages always agree.
 */
final readonly class BenchmarkCandidateStats
{
    /**
     * @param  Collection<int, BenchmarkResult>  $results
     * @return array{results: int, ok: int, failed: int, skipped: int, scored: int, avg_score: float|null, pass_rate: float|null, cost_per_case: float|null, latency_p50: int|null, latency_p95: int|null, tool_misses: int}
     */
    public static function of(Collection $results): array
    {
        $ok = $results->where('status', BenchmarkResultStatus::Ok);
        $scores = $ok->whereNotNull('score')->pluck('score');
        $verdicts = $ok->whereNotNull('passed');
        $latencies = $ok->whereNotNull('latency_ms')->pluck('latency_ms')->map(fn (mixed $latency): int => (int) $latency)->sort()->values();
        $cost = $results->sum(fn (BenchmarkResult $benchmarkResult): float => (float) $benchmarkResult->cost_usd + (float) $benchmarkResult->judge_cost_usd);

        return [
            'results' => $results->count(),
            'ok' => $ok->count(),
            'failed' => $results->where('status', BenchmarkResultStatus::Failed)->count(),
            'skipped' => $results->where('status', BenchmarkResultStatus::Skipped)->count(),
            'scored' => $scores->count(),
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
}
