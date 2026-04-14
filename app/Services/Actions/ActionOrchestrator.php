<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;

class ActionOrchestrator
{
    /**
     * Dispatch an action request. Looks up the ActionTypeConfig for the given type:
     *   - If the type is not configured or is disabled, logs and returns null
     *   - Otherwise creates the ActionRequest with the right approval setting
     *   - If auto-execute (requires_approval=false), queues ExecuteActionRequest
     *   - Always fires ActionRequestCreated for frontend notification
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        string $type,
        string $sourceService,
        string $targetService,
        array $payload,
        ?WebhookEvent $webhookEvent = null,
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

        $actionRequest = ActionRequest::create([
            'webhook_event_id' => $webhookEvent?->id,
            'type' => $type,
            'source_service' => $sourceService,
            'target_service' => $targetService,
            'status' => $config->requires_approval
                ? ActionRequestStatus::Pending
                : ActionRequestStatus::Approved,
            'requires_approval' => $config->requires_approval,
            'payload' => $payload,
        ]);

        event(new ActionRequestCreated($actionRequest));

        if (! $config->requires_approval) {
            dispatch(new ExecuteActionRequest($actionRequest));
        }

        return $actionRequest;
    }
}
