<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Models\WebhookEvent;

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

    /**
     * The payload the describer resolves against: the triggering webhook's
     * connection is pinned exactly as ActionOrchestrator::dispatchFromAgent()
     * will pin it, so the named target is the one the executor acts on.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function pinContext(array $payload): array
    {
        if ($this->webhookEventId === null || array_key_exists('service_connection_id', $payload)) {
            return $payload;
        }

        $connectionId = WebhookEvent::query()->whereKey($this->webhookEventId)->value('service_connection_id');

        return $connectionId === null ? $payload : [...$payload, 'service_connection_id' => $connectionId];
    }

    public function proposalReason(): string
    {
        $webhookEvent = $this->webhookEventId === null
            ? null
            : WebhookEvent::query()->with('serviceConnection:id,name')->find($this->webhookEventId);

        if (! $webhookEvent instanceof WebhookEvent) {
            return 'Proposed by the decision agent.';
        }

        return sprintf(
            'Proposed by the decision agent in response to a "%s" event from %s.',
            $webhookEvent->event_type,
            $webhookEvent->serviceConnection?->name ?? $this->sourceService,
        );
    }

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
