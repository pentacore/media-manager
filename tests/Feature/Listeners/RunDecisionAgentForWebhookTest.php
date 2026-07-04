<?php

declare(strict_types=1);

use App\Events\WebhookEventProcessed;
use App\Jobs\RunDecisionAgent;
use App\Listeners\RunDecisionAgentForWebhook;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    config(['mediamanager.ai.enabled' => true]);
});

function sonarrWebhookEvent(string $eventType): WebhookEvent
{
    $connection = ServiceConnection::factory()->sonarr()->create();

    return WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => $eventType,
        'payload' => ['eventType' => $eventType, 'series' => ['title' => 'Demo']],
    ]);
}

function handleProcessed(WebhookEvent $webhookEvent): void
{
    resolve(RunDecisionAgentForWebhook::class)->handle(new WebhookEventProcessed($webhookEvent));
}

test('dispatches RunDecisionAgent for an enabled, allowlisted event', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setEnabled(true);
    $decisionAgentSettings->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    $webhookEvent = sonarrWebhookEvent('ManualInteractionRequired');
    handleProcessed($webhookEvent);

    Queue::assertPushed(RunDecisionAgent::class, fn (RunDecisionAgent $runDecisionAgent): bool => $runDecisionAgent->webhookEventId === $webhookEvent->id
        && $runDecisionAgent->service === 'sonarr'
        && $runDecisionAgent->eventType === 'ManualInteractionRequired');
});

test('does nothing when the agent is disabled', function (): void {
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    handleProcessed(sonarrWebhookEvent('ManualInteractionRequired'));

    Queue::assertNotPushed(RunDecisionAgent::class);
});

test('does nothing for an event not on the allowlist', function (): void {
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setEnabled(true);
    $decisionAgentSettings->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    handleProcessed(sonarrWebhookEvent('Download'));

    Queue::assertNotPushed(RunDecisionAgent::class);
});

test('does nothing when the AI feature is disabled', function (): void {
    config(['mediamanager.ai.enabled' => false]);
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setEnabled(true);
    $decisionAgentSettings->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    handleProcessed(sonarrWebhookEvent('ManualInteractionRequired'));

    Queue::assertNotPushed(RunDecisionAgent::class);
});
