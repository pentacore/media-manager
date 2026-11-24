<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Enums\BenchmarkJudgeStatus;
use App\Jobs\RunBenchmarkCase;
use App\Models\BenchmarkResult;
use Closure;

/**
 * At most ConcurrencySlots::SLOTS RunBenchmarkCase jobs of one run execute
 * at a time; a job finding every slot taken goes back on the queue.
 *
 * Re-judging queues every unscored result at once, so a judge-only job may
 * wait for a slot longer than the stale window: each release touches its
 * still-queued result, so a waiting re-judge never reads as stalled. A case
 * job's waiting result stays `pending`, which never goes stale.
 */
final readonly class LimitBenchmarkConcurrency
{
    public function __construct(private int $resultId) {}

    public function handle(RunBenchmarkCase $runBenchmarkCase, Closure $next): mixed
    {
        $runId = BenchmarkResult::query()->whereKey($this->resultId)->value('benchmark_run_id');

        if ($runId === null) {
            return $next($runBenchmarkCase);
        }

        return ConcurrencySlots::run(
            sprintf('benchmark:%d', $runId),
            fn (): mixed => $next($runBenchmarkCase),
            function (int $retryAfter) use ($runBenchmarkCase): void {
                if ($runBenchmarkCase->judgeOnly) {
                    BenchmarkResult::query()
                        ->whereKey($this->resultId)
                        ->where('judge_status', BenchmarkJudgeStatus::Queued)
                        ->touch();
                }

                $runBenchmarkCase->release($retryAfter);
            },
        );
    }
}
