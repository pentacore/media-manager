<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

/**
 * Outcome of one operator action. `ok` decides the toast type; `message` is
 * already translated and safe to show verbatim.
 */
final readonly class OperatorActionResult
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}
}
