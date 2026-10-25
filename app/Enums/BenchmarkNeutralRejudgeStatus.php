<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum BenchmarkNeutralRejudgeStatus: string
{
    use EnumUtils;

    case Running = 'running';
    case Completed = 'completed';
    case StoppedCostCap = 'stopped_cost_cap';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::StoppedCostCap => 'Stopped at cost cap',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
