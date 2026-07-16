<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum SubtitleCaseStatus: string
{
    use EnumUtils;

    case Observing = 'observing';
    case BazarrSearching = 'bazarr_searching';
    case DownloadRequested = 'download_requested';
    case ReplacementEligible = 'replacement_eligible';
    case AdvisorRunning = 'advisor_running';
    case ReplacementRequested = 'replacement_requested';
    case NeedsReview = 'needs_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
    case Handled = 'handled';
    case Superseded = 'superseded';
}
