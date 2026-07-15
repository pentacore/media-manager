<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum SeasonPackPolicy: string
{
    use EnumUtils;

    case Never = 'never';
    case ApprovalRequired = 'approval_required';
    case AutomaticAboveThreshold = 'automatic_above_threshold';
}
