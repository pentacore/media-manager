<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WebhookEventProcessed;
use App\Jobs\RunDecisionAgent;
use App\Providers\AIServiceProvider;
use App\Settings\DecisionAgentSettings;

/**
 * Bridges a processed webhook to the autonomous DecisionAgent. Fires
 * synchronously off WebhookEventProcessed (while the row still exists),
 * does the cheap enabled + allowlist gating, and hands a payload snapshot
 * to a queued RunDecisionAgent so the LLM call never blocks webhook
 * processing — and survives the row being trimmed when capture is off.
 */
class RunDecisionAgentForWebhook
{
    public function __construct(private readonly DecisionAgentSettings $decisionAgentSettings) {}

    public function handle(WebhookEventProcessed $webhookEventProcessed): void
    {
        if (! AIServiceProvider::enabled() || ! $this->decisionAgentSettings->enabled()) {
            return;
        }

        $webhookEvent = $webhookEventProcessed->webhookEvent;
        $webhookEvent->loadMissing('serviceConnection');

        $service = $webhookEvent->serviceConnection?->type->value;
        if ($service === null) {
            return;
        }

        $eventType = $webhookEvent->event_type;

        if (! $this->decisionAgentSettings->isEventAllowed($service, $eventType)) {
            return;
        }

        dispatch(new RunDecisionAgent(
            webhookEventId: $webhookEvent->id,
            service: $service,
            eventType: $eventType,
            payload: $webhookEvent->payload,
        ));
    }
}
