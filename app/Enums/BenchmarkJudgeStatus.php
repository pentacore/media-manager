<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * Where a benchmark result's judging stands. `queued` and `pending` are
 * transient: a re-judge was requested and its judge-only job is queued, or
 * that job claimed the result and is judging it.
 */
enum BenchmarkJudgeStatus: string
{
    use EnumUtils;

    case None = 'none';
    case Ok = 'ok';
    case Failed = 'failed';
    case Queued = 'queued';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not judged',
            self::Ok => 'Judged',
            self::Failed => 'Judge failed',
            self::Queued => 'Queued for re-judging',
            self::Pending => 'Re-judging',
        };
    }

    /**
     * A re-judge still waiting for or getting its score.
     */
    public function isRejudging(): bool
    {
        return in_array($this, [self::Queued, self::Pending], true);
    }
}
