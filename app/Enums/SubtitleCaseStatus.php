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

    /**
     * Statuses whose rows may be pruned once old. Dismissed and Handled are
     * operator decisions the reconciler finds by file identity to keep a file
     * out of automation, so they stay on the record.
     *
     * @return list<self>
     */
    public static function prunableStatuses(): array
    {
        return [self::Resolved, self::Superseded];
    }
}
