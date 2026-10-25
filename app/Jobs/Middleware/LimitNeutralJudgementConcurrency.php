<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Enums\BenchmarkJudgeStatus;
use App\Jobs\RunNeutralJudgement;
use App\Models\BenchmarkNeutralJudgement;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * At most two judgements of one neutral re-judge run at a time, like
 * LimitBenchmarkConcurrency does for a run. A waiting judgement is touched on
 * every release, so it never reads as stalled.
 */
final readonly class LimitNeutralJudgementConcurrency
{
    public const int SLOTS = 2;

    private const int LOCK_SECONDS = 300;

    private const int RETRY_AFTER_SECONDS = 15;

    public function __construct(private int $judgementId) {}

    public function handle(RunNeutralJudgement $runNeutralJudgement, Closure $next): mixed
    {
        $rejudgeId = BenchmarkNeutralJudgement::query()->whereKey($this->judgementId)->value('benchmark_neutral_rejudge_id');

        if ($rejudgeId === null) {
            return $next($runNeutralJudgement);
        }

        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $lock = Cache::lock(sprintf('benchmark:neutral:%d:slot:%d', $rejudgeId, $slot), self::LOCK_SECONDS);

            if (! $lock->get()) {
                continue;
            }

            try {
                return $next($runNeutralJudgement);
            } finally {
                $lock->release();
            }
        }

        BenchmarkNeutralJudgement::query()
            ->whereKey($this->judgementId)
            ->where('status', BenchmarkJudgeStatus::Queued)
            ->touch();

        $runNeutralJudgement->release(self::RETRY_AFTER_SECONDS);

        return null;
    }
}
