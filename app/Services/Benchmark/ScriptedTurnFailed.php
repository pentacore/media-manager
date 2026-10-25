<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use RuntimeException;
use Throwable;

/**
 * A turn of a scripted chat case failed. Carries what the earlier turns
 * produced; the job stores that partial outcome on the failed result.
 */
final class ScriptedTurnFailed extends RuntimeException
{
    public function __construct(int $turn, int $turns, public readonly CaseOutcome $partialOutcome, Throwable $previous)
    {
        parent::__construct(sprintf('Turn %d of %d failed: %s', $turn, $turns, $previous->getMessage()), 0, $previous);
    }
}
