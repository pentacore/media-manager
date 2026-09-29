<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use RuntimeException;

final class ReplacementInFlight extends RuntimeException
{
    public static function forTitle(): self
    {
        return new self('A file replacement is in progress for this title, so monitoring was left unchanged. Try again when it finishes.');
    }
}
