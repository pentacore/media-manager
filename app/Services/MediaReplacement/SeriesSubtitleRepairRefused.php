<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use RuntimeException;

/**
 * A series subtitle repair could not start: nothing is missing, the
 * replacement action type is disabled, or a batch for the series is still searching.
 */
final class SeriesSubtitleRepairRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('The series subtitle repair was refused: %s.', $reason));
    }
}
