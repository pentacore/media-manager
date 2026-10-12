<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum BenchmarkResultStatus: string
{
    use EnumUtils;

    case Pending = 'pending';
    case Ok = 'ok';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Ok => 'OK',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
