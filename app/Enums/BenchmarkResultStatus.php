<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum BenchmarkResultStatus: string
{
    use EnumUtils;

    case Pending = 'pending';
    case Running = 'running';
    case Ok = 'ok';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Ok => 'OK',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    /**
     * Not finished yet: queued for its job, or claimed by it and running.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}
