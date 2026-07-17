<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ActionRequestStatus;
use App\Enums\AiMode;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\WebhookEvent;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Log;

class ActionOrchestrator
{
    public function __construct(private readonly AiSettings $aiSettings) {}

    /**
     * Dispatch an action request. Looks up the ActionTypeConfig for the given type:
     *   - If the type is not configured or is disabled, logs and returns null
     *   - Otherwise creates the ActionRequest with the right approval setting
     *   - If auto-execute (requires_approval=false), queues ExecuteActionRequest
     *   - Always fires ActionRequestCreated for frontend notification
     *
     * Advisory mode override: when AiSettings::mode() === Advisory, every request
     * is forced to Pending regardless of ActionTypeConfig.requires_approval, and
     * ExecuteActionRequest is never dispatched.
     *
     * $forceRequiresApproval lets a caller add (never remove) an approval
     * requirement for a single instance — e.g. a partially-mapped manual import
     * is forced to Pending even when its ActionTypeConfig auto-executes.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        string $type,
        string $sourceService,
        string $targetService,
        array $payload,
        ?WebhookEvent $webhookEvent = null,
        ?bool $forceRequiresApproval = null,
    ): ?ActionRequest {
        $config = ActionTypeConfig::where('type', $type)->first();

        if ($config === null) {
            Log::info('ActionOrchestrator: no ActionTypeConfig for type, skipping', [
                'type' => $type,
                'webhook_event_id' => $webhookEvent?->id,
            ]);

            return null;
        }

        if (! $config->is_enabled) {
            Log::info('ActionOrchestrator: ActionTypeConfig disabled, skipping', [
                'type' => $type,
                'webhook_event_id' => $webhookEvent?->id,
            ]);

            return null;
        }

        $advisoryMode = $this->aiSettings->mode() === AiMode::Advisory;
        // The override can only tighten the gate (force approval), never relax it.
        $requiresApproval = $advisoryMode || $config->requires_approval || ($forceRequiresApproval ?? false);

        // Pin the originating connection so executors act on the instance
        // that emitted the event — media IDs overlap across same-type
        // instances, and re-resolving "the active one" at execution time
        // could target a different server.
        if ($webhookEvent?->service_connection_id !== null && ! array_key_exists('service_connection_id', $payload)) {
            $payload['service_connection_id'] = $webhookEvent->service_connection_id;
        }

        $actionRequest = ActionRequest::create([
            'webhook_event_id' => $webhookEvent?->id,
            'type' => $type,
            'source_service' => $sourceService,
            'target_service' => $targetService,
            'status' => $requiresApproval
                ? ActionRequestStatus::Pending
                : ActionRequestStatus::Approved,
            'requires_approval' => $requiresApproval,
            'payload' => $payload,
        ]);

        event(new ActionRequestCreated($actionRequest));

        if (! $requiresApproval) {
            dispatch(new ExecuteActionRequest($actionRequest))->afterCommit();
        }

        return $actionRequest;
    }

    /**
     * Dispatch an action proposed by the autonomous DecisionAgent.
     *
     * Unlike dispatch(), this path is NOT subject to the chat AiMode advisory
     * override — the DecisionAgent has its own enablement and the suggest-vs-act
     * decision is governed solely by the per-type ActionTypeConfig.requires_approval
     * flag. The request is tagged origin='agent' and the agent's rationale is
     * folded into the payload so it surfaces on the actions UI.
     *
     * $forceRequiresApproval lets a caller add (never remove) an approval
     * requirement for a single instance — e.g. an ambiguous manual import is
     * forced to Pending even when its ActionTypeConfig auto-executes.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatchFromAgent(
        string $type,
        string $sourceService,
        string $targetService,
        array $payload,
        string $rationale,
        ?int $webhookEventId = null,
        ?bool $forceRequiresApproval = null,
    ): ?ActionRequest {
        $config = ActionTypeConfig::where('type', $type)->first();

        if ($config === null || ! $config->is_enabled) {
            Log::info('ActionOrchestrator: agent action type missing or disabled, skipping', [
                'type' => $type,
                'webhook_event_id' => $webhookEventId,
            ]);

            return null;
        }

        // The override can only tighten the gate (force approval), never relax it.
        $requiresApproval = $config->requires_approval || ($forceRequiresApproval ?? false);

        // Same connection pinning as dispatch(): agent proposals originate
        // from a webhook event too.
        if ($webhookEventId !== null && ! array_key_exists('service_connection_id', $payload)) {
            $originConnectionId = WebhookEvent::query()->whereKey($webhookEventId)->value('service_connection_id');

            if ($originConnectionId !== null) {
                $payload['service_connection_id'] = $originConnectionId;
            }
        }

        $actionRequest = ActionRequest::create([
            'webhook_event_id' => $webhookEventId,
            'type' => $type,
            'origin' => 'agent',
            'source_service' => $sourceService,
            'target_service' => $targetService,
            'status' => $requiresApproval
                ? ActionRequestStatus::Pending
                : ActionRequestStatus::Approved,
            'requires_approval' => $requiresApproval,
            'payload' => [...$payload, 'agent_rationale' => $rationale],
        ]);

        event(new ActionRequestCreated($actionRequest));

        if (! $requiresApproval) {
            dispatch(new ExecuteActionRequest($actionRequest))->afterCommit();
        }

        return $actionRequest;
    }
}
