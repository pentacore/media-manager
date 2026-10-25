<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use RuntimeException;

/**
 * The series cannot be audited, e.g. its root folder has no library type.
 */
final class SeriesSubtitleAuditUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('The series subtitle audit is unavailable: %s.', $reason));
    }
}
