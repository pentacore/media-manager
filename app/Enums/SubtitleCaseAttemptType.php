<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum SubtitleCaseAttemptType: string
{
    use EnumUtils;

    case Probe = 'probe';
    case Download = 'download';
    case Advisor = 'advisor';
    case Reconciliation = 'reconciliation';
}
