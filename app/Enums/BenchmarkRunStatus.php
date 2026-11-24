<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum BenchmarkRunStatus: string
{
    use EnumUtils;

    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case StoppedCostCap = 'stopped_cost_cap';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::StoppedCostCap => 'Stopped at cost cap',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    /**
     * The statuses a run is comparable in whatever its results: it ran to the
     * end or to its cap. A cancelled run is comparable only once a case
     * finished (BenchmarkRun::isComparable()).
     *
     * @return list<self>
     */
    public static function comparable(): array
    {
        return [self::Completed, self::StoppedCostCap];
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::Running], true);
    }
}
