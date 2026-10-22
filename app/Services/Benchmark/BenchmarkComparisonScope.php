<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkResult;

/**
 * Which cases a set of runs can be compared on: per task, the case ids every
 * run has a finished result (ok or failed, any candidate) for, and how many
 * case ids only some of them finished. Skipped, pending and running results
 * never count (a capped or cancelled run skips its never-run cases), nor do
 * results of deleted cases.
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
            ->whereIn('status', [BenchmarkResultStatus::Ok, BenchmarkResultStatus::Failed])
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
