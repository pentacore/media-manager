<?php

declare(strict_types=1);

namespace App\Ai\Decision;

/**
 * Per-run scratch state shared between RunDecisionAgent and ProposeActionTool.
 *
 * laravel/ai resolves tools fresh from the container, so the tool can't hold
 * run state itself. The job binds an instance of this into the container
 * before prompting; the tool reads the limits and records each ActionRequest
 * it queues; the job reads the tally back afterwards to finalize the
 * AgentDecision and decide whether to notify.
 */
class DecisionRunContext
{
    /** @var array<int, int> */
    private array $actionRequestIds = [];

    /** @var array<int, array{action_request_id: int, requires_approval: bool}> */
    private array $queued = [];

    public function __construct(
        public readonly ?int $webhookEventId,
        public readonly int $maxActions,
        public readonly string $sourceService = 'agent',
    ) {}

    public function remainingBudget(): int
    {
        return max(0, $this->maxActions - count($this->actionRequestIds));
    }

    public function capReached(): bool
    {
        return $this->remainingBudget() <= 0;
    }

    public function recordQueued(int $actionRequestId, bool $requiresApproval): void
    {
        $this->actionRequestIds[] = $actionRequestId;
        $this->queued[] = [
            'action_request_id' => $actionRequestId,
            'requires_approval' => $requiresApproval,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function actionRequestIds(): array
    {
        return $this->actionRequestIds;
    }

    public function count(): int
    {
        return count($this->actionRequestIds);
    }

    public function suggestedCount(): int
    {
        return count(array_filter($this->queued, static fn (array $row): bool => $row['requires_approval']));
    }

    public function actedCount(): int
    {
        return count(array_filter($this->queued, static fn (array $row): bool => ! $row['requires_approval']));
    }
}
