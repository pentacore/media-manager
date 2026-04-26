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

        $advisoryMode = $this->aiSettings->mode() === AiMode::Advisory;
        $requiresApproval = $advisoryMode || $config->requires_approval;

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
            dispatch(new ExecuteActionRequest($actionRequest));
        }

        return $actionRequest;
    }
}
