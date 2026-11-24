<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Enums\BenchmarkJudgeStatus;
use App\Jobs\RunNeutralJudgement;
use App\Models\BenchmarkNeutralJudgement;
use Closure;

/**
 * At most ConcurrencySlots::SLOTS judgements of one neutral re-judge run at
 * a time, like LimitBenchmarkConcurrency does for a run. A waiting judgement
 * is touched on every release, so it never reads as stalled.
 */
final readonly class LimitNeutralJudgementConcurrency
{
    public function __construct(private int $judgementId) {}

    public function handle(RunNeutralJudgement $runNeutralJudgement, Closure $next): mixed
    {
        $rejudgeId = BenchmarkNeutralJudgement::query()->whereKey($this->judgementId)->value('benchmark_neutral_rejudge_id');

        if ($rejudgeId === null) {
            return $next($runNeutralJudgement);
        }

        return ConcurrencySlots::run(
            sprintf('benchmark:neutral:%d', $rejudgeId),
            fn (): mixed => $next($runNeutralJudgement),
            function (int $retryAfter) use ($runNeutralJudgement): void {
                BenchmarkNeutralJudgement::query()
                    ->whereKey($this->judgementId)
                    ->where('status', BenchmarkJudgeStatus::Queued)
                    ->touch();

                $runNeutralJudgement->release($retryAfter);
            },
        );
    }
}
