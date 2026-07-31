<?php

declare(strict_types=1);

namespace App\Ai\SubtitleAdvisor;

use LogicException;

final class SubtitleAdvisorRunContext
{
    private ?int $queuedActionRequestId = null;

    public function __construct(
        public readonly int $caseId,
        public readonly int $maxActions = 1,
    ) {}

    public function actionRequestId(): ?int
    {
        return $this->queuedActionRequestId;
    }

    public function capReached(): bool
    {
        return $this->queuedActionRequestId !== null || $this->maxActions < 1;
    }

    public function recordQueued(int $actionRequestId): void
    {
        throw_if($this->capReached(), LogicException::class, 'The subtitle Advisor action cap has been reached.');

        $this->queuedActionRequestId = $actionRequestId;
    }
}
