<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Models\BenchmarkResult;

/**
 * Which cases a set of runs can be compared on: per task, the case ids every
 * run has a result for (any candidate, any status), and how many case ids
 * only some of them have. Results of deleted cases never count.
 */
final readonly class BenchmarkComparisonScope
{
    /**
     * @param  list<int>  $runIds
     * @return array<string, array{common: list<int>, excluded: int}>
     */
    public static function commonCases(array $runIds): array
    {
        $rows = BenchmarkResult::query()
            ->whereIn('benchmark_run_id', $runIds)
            ->whereNotNull('benchmark_case_id')
            ->select(['case_task', 'benchmark_case_id'])
            ->selectRaw('count(distinct benchmark_run_id) as run_count')
            ->groupBy('case_task', 'benchmark_case_id')
            ->orderBy('benchmark_case_id')
            ->toBase()
            ->get();

        $tasks = [];

        foreach ($rows as $row) {
            $task = (string) $row->case_task;
            $tasks[$task] ??= ['common' => [], 'excluded' => 0];

            if ((int) $row->run_count === count($runIds)) {
                $tasks[$task]['common'][] = (int) $row->benchmark_case_id;
            } else {
                $tasks[$task]['excluded']++;
            }
        }

        return $tasks;
    }
}
