<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum BenchmarkScheduleOutcome: string
{
    use EnumUtils;

    case Launched = 'launched';
    case SkippedNoCases = 'skipped_no_cases';
    case SkippedNoCandidates = 'skipped_no_candidates';
    case SkippedRunning = 'skipped_running';
    case SkippedOverCap = 'skipped_over_cap';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Launched => 'Launched',
            self::SkippedNoCases => 'Skipped: no cases',
            self::SkippedNoCandidates => 'Skipped: no tier models',
            self::SkippedRunning => 'Skipped: still running',
            self::SkippedOverCap => 'Skipped: over cost cap',
            self::Failed => 'Failed to start',
        };
    }

    public function isSkip(): bool
    {
        return $this !== self::Launched;
    }
}
