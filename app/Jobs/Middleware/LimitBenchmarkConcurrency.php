<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Enums\BenchmarkJudgeStatus;
use App\Jobs\RunBenchmarkCase;
use App\Models\BenchmarkResult;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * At most two RunBenchmarkCase jobs of one run execute at a time: each holds
 * one of the run's two slot locks for its lifetime; a job finding both taken
 * goes back on the queue. The lock TTL outlives the job timeout, so a killed
 * worker frees its slot on its own.
 *
 * Re-judging queues every unscored result at once, so a judge-only job may
 * wait for a slot longer than the stale window: each release touches its
 * still-queued result, so a waiting re-judge never reads as stalled. A case
 * job's waiting result stays `pending`, which never goes stale.
 */
final readonly class LimitBenchmarkConcurrency
{
    public const int SLOTS = 2;

    private const int LOCK_SECONDS = 300;

    private const int RETRY_AFTER_SECONDS = 15;

    public function __construct(private int $resultId) {}

    public function handle(RunBenchmarkCase $runBenchmarkCase, Closure $next): mixed
    {
        $runId = BenchmarkResult::query()->whereKey($this->resultId)->value('benchmark_run_id');

        if ($runId === null) {
            return $next($runBenchmarkCase);
        }

        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $lock = Cache::lock(sprintf('benchmark:%d:slot:%d', $runId, $slot), self::LOCK_SECONDS);

            if (! $lock->get()) {
                continue;
            }

            try {
                return $next($runBenchmarkCase);
            } finally {
                $lock->release();
            }
        }

        if ($runBenchmarkCase->judgeOnly) {
            BenchmarkResult::query()
                ->whereKey($this->resultId)
                ->where('judge_status', BenchmarkJudgeStatus::Queued)
                ->touch();
        }

        $runBenchmarkCase->release(self::RETRY_AFTER_SECONDS);

        return null;
    }
}
