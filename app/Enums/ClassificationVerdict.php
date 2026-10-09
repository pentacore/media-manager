<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * What the app did with a classification answer.
 */
enum ClassificationVerdict: string
{
    use EnumUtils;

    case Skipped = 'skipped';
    case Passed = 'passed';
    case AuditRun = 'audit_run';
    case Scoped = 'scoped';
    case Unscoped = 'unscoped';
    case ResolvedByClassifier = 'resolved_by_classifier';
    case Fallback = 'fallback';
    case Included = 'included';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Skipped => 'Skipped',
            self::Passed => 'Passed',
            self::AuditRun => 'Audit run',
            self::Scoped => 'Scoped',
            self::Unscoped => 'Unscoped',
            self::ResolvedByClassifier => 'Resolved by classifier',
            self::Fallback => 'Fell back to the agent',
            self::Included => 'Included',
            self::Excluded => 'Excluded',
        };
    }
}
