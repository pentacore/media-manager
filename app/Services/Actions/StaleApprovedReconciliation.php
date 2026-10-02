<?php

declare(strict_types=1);

namespace App\Services\Actions;

/**
 * Outcome of one StaleApprovedRequestRedispatcher::redispatch() run.
 */
final readonly class StaleApprovedReconciliation
{
    public function __construct(
        public int $redispatched,
        public int $neverStarted,
    ) {}
}
