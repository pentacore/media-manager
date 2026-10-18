<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Ai\Routing\DecisionActionKind;
use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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

    /** @var array<int, int> */
    private array $rejectedIds = [];

    private int $heldBySecondOpinion = 0;

    /**
     * $eventPayload and $originConnectionId are the triggering event's
     * snapshot, carried by the job rather than re-read from the WebhookEvent
     * row: with webhook capture off that row is trimmed before the run (and
     * $webhookEventId is then null), yet subject binding and connection
     * pinning must still hold.
     *
     * $eventType is the triggering event's type, carried the same way as
     * $eventPayload/$originConnectionId, so the approval-card reason still
     * names the event when the WebhookEvent row is gone.
     *
     * $actionKind is the action kind the gate scoped this run to, or null for
     * the full toolset.
     *
     * $deadline is when the run must be done to finish inside its job
     * timeout (null when nothing bounds it); the second-opinion check stops
     * short of it.
     *
     * @param  array<string, mixed>  $eventPayload
     */
    public function __construct(
        public readonly ?int $webhookEventId,
        public readonly int $maxActions,
        public readonly string $sourceService = 'agent',
        public readonly array $eventPayload = [],
        public readonly ?int $originConnectionId = null,
        public readonly ?string $eventType = null,
        public readonly ?DecisionActionKind $actionKind = null,
        public readonly ?CarbonImmutable $deadline = null,
    ) {}

    /**
     * The triggering event's download id, read the way the arr webhook
     * handlers read it: top-level downloadId, falling back to
     * downloadInfo.downloadId. Shared by every tool that must bind its
     * action to the download that triggered this run.
     */
    public function eventDownloadId(): ?string
    {
        $downloadId = $this->eventPayload['downloadId'] ?? ($this->eventPayload['downloadInfo']['downloadId'] ?? null);

        return is_string($downloadId) && $downloadId !== '' ? $downloadId : null;
    }

    /**
     * The payload the describer resolves against: the triggering webhook's
     * connection is pinned exactly as ActionOrchestrator::dispatchFromAgent()
     * will pin it (tools pass $originConnectionId as its pinnedConnectionId)
     * — always overwriting any model-supplied service_connection_id — so the
     * named target is the one the executor acts on.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function pinContext(array $payload): array
    {
        if ($this->webhookEventId === null && $this->originConnectionId === null) {
            return $payload;
        }

        unset($payload['service_connection_id']);

        return $this->originConnectionId === null
            ? $payload
            : [...$payload, 'service_connection_id' => $this->originConnectionId];
    }

    /**
     * Resolve the connection candidate-lookup and inspection tools should
     * read from: the pinned connection this run's action executes on when an
     * origin connection is known, the active connection otherwise. Without
     * this, a tool re-resolving "the active" connection could inspect a
     * different instance than the one the queued action will run against on
     * a multi-instance setup.
     *
     * @throws ModelNotFoundException
     */
    public function resolveConnection(ServiceType $serviceType): ServiceConnection
    {
        return ServiceConnection::resolvePinned($this->pinContext([]), $serviceType);
    }

    public function proposalReason(): string
    {
        if ($this->eventType !== null) {
            $connectionName = $this->originConnectionId === null
                ? null
                : ServiceConnection::query()->find($this->originConnectionId)?->name;

            return sprintf(
                'Proposed by the decision agent in response to a "%s" event from %s.',
                $this->eventType,
                $connectionName ?? $this->sourceService,
            );
        }

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

    /**
     * Whole seconds left before the run's deadline (negative once past it),
     * or null when nothing bounds the run.
     */
    public function secondsLeft(): ?int
    {
        return $this->deadline instanceof CarbonImmutable
            ? $this->deadline->getTimestamp() - now()->getTimestamp()
            : null;
    }

    /**
     * The per-provider timeout that lets a whole failover chain of
     * $providers finish $reservedSeconds before the deadline, capped at
     * $maxSeconds. Callers decide what a too-short result means: the second
     * opinion skips its check, the DecisionAgent applies a floor.
     */
    public static function failoverTimeout(int $secondsLeft, int $reservedSeconds, int $providers, int $maxSeconds): int
    {
        return min($maxSeconds, intdiv($secondsLeft - $reservedSeconds, max(1, $providers)));
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
     * Tally a request dispatchFromAgent() created. A request the second
     * opinion rejected still uses up the run's cap and is linked to the
     * decision, but is neither suggested nor acted.
     */
    public function recordDispatched(ActionRequest $actionRequest): void
    {
        if ($actionRequest->status === ActionRequestStatus::Rejected) {
            $this->actionRequestIds[] = $actionRequest->id;
            $this->rejectedIds[] = $actionRequest->id;

            return;
        }

        $this->recordQueued($actionRequest->id, $actionRequest->requires_approval);

        if ($actionRequest->requires_approval && ($actionRequest->second_opinion['agrees'] ?? true) === false) {
            $this->heldBySecondOpinion++;
        }
    }

    public function rejectedCount(): int
    {
        return count($this->rejectedIds);
    }

    public function heldBySecondOpinionCount(): int
    {
        return $this->heldBySecondOpinion;
    }

    /**
     * The tool result for a proposal the second opinion rejected. It never
     * carries the verifier's reason, so the proposer cannot argue with it.
     *
     * @return array<string, mixed>
     */
    public function secondOpinionRejection(int $actionRequestId): array
    {
        return [
            'queued' => false,
            'reason' => 'second_opinion_rejected',
            'action_request_id' => $actionRequestId,
            'remaining_budget' => $this->remainingBudget(),
            'message' => 'An independent second-opinion check rejected this action, so it will not run. Do not propose it again; mention it in your summary.',
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

    /**
     * Whether this run loads only its action kind's tools.
     */
    public function isScoped(): bool
    {
        return $this->actionKind instanceof DecisionActionKind && $this->actionKind !== DecisionActionKind::Other;
    }

    /**
     * The declared tools this run may use: the core plus the action kind's
     * tools when scoped, every declared tool otherwise.
     *
     * @param  array<int, object>  $tools
     * @return array<int, object>
     */
    public function scopeTools(array $tools): array
    {
        if (! $this->isScoped()) {
            return $tools;
        }

        $allowed = [...DecisionActionKind::coreTools(), ...($this->actionKind?->toolClasses() ?? [])];

        return array_values(array_filter($tools, static fn (object $tool): bool => in_array($tool::class, $allowed, true)));
    }
}
