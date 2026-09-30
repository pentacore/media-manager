<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * Which feed an activity_logs row belongs to. `audit` rows record admin
 * changes (users, connections, removals, settings) with masked diffs and are
 * visible to admins only — see the ActivityLog model's visibleTo scope.
 */
enum ActivityLogCategory: string
{
    use EnumUtils;

    case Activity = 'activity';
    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::Activity => 'Activity',
            self::Audit => 'Audit',
        };
    }

    public function isAdminOnly(): bool
    {
        return $this === self::Audit;
    }
}
