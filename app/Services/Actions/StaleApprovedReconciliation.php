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

    /**
     * @return array{redispatched: int, never_started: int}
     */
    public function toArray(): array
    {
        return [
            'redispatched' => $this->redispatched,
            'never_started' => $this->neverStarted,
        ];
    }
}
