<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum SubtitleCaseAttemptOutcome: string
{
    use EnumUtils;

    case Started = 'started';
    case Succeeded = 'succeeded';
    case Empty = 'empty';
    case Failed = 'failed';
    case Indeterminate = 'indeterminate';
    case NeedsReview = 'needs_review';
}
