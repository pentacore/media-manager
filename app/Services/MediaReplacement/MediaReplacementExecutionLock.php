<?php

namespace App\Services\MediaReplacement;

use InvalidArgumentException;

/**
 * Canonical ownership lock shared by replacement execution and reconciliation.
 */
final readonly class MediaReplacementExecutionLock
{
    /**
     * Longer than ExecuteActionRequest's 300-second timeout and queue handover.
     */
    public const int TTL_SECONDS = 900;

    public static function key(int $actionRequestId): string
    {
        throw_unless(
            $actionRequestId > 0,
            InvalidArgumentException::class,
            'Media replacement execution lock requires a positive action request id.',
        );

        return 'media-replacement-execution:'.$actionRequestId;
    }
}
