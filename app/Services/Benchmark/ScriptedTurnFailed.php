<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use RuntimeException;
use Throwable;

/**
 * A turn of a scripted chat case failed or could not start. Carries what the
 * earlier turns produced; the job stores that partial outcome on the failed
 * result.
 */
final class ScriptedTurnFailed extends RuntimeException
{
    private function __construct(string $message, public readonly CaseOutcome $partialOutcome, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function turnThrew(int $turn, int $turns, CaseOutcome $partialOutcome, Throwable $previous): self
    {
        return new self(sprintf('Turn %d of %d failed: %s', $turn, $turns, $previous->getMessage()), $partialOutcome, $previous);
    }

    public static function budgetUsed(int $turn, int $turns, int $budgetSeconds, CaseOutcome $partialOutcome): self
    {
        return new self(sprintf('Turn %d of %d not started: the case used its %d s time budget.', $turn, $turns, $budgetSeconds), $partialOutcome);
    }
}
