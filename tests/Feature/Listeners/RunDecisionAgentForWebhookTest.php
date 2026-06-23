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

function handleProcessed(WebhookEvent $event): void
{
    resolve(RunDecisionAgentForWebhook::class)->handle(new WebhookEventProcessed($event));
}

test('dispatches RunDecisionAgent for an enabled, allowlisted event', function (): void {
    $settings = resolve(DecisionAgentSettings::class);
    $settings->setEnabled(true);
    $settings->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    $event = sonarrWebhookEvent('ManualInteractionRequired');
    handleProcessed($event);

    Queue::assertPushed(RunDecisionAgent::class, function (RunDecisionAgent $job) use ($event): bool {
        return $job->webhookEventId === $event->id
            && $job->service === 'sonarr'
            && $job->eventType === 'ManualInteractionRequired';
    });
});

test('does nothing when the agent is disabled', function (): void {
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    handleProcessed(sonarrWebhookEvent('ManualInteractionRequired'));

    Queue::assertNotPushed(RunDecisionAgent::class);
});

test('does nothing for an event not on the allowlist', function (): void {
    $settings = resolve(DecisionAgentSettings::class);
    $settings->setEnabled(true);
    $settings->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    handleProcessed(sonarrWebhookEvent('Download'));

    Queue::assertNotPushed(RunDecisionAgent::class);
});

test('does nothing when the AI feature is disabled', function (): void {
    config(['mediamanager.ai.enabled' => false]);
    $settings = resolve(DecisionAgentSettings::class);
    $settings->setEnabled(true);
    $settings->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    handleProcessed(sonarrWebhookEvent('ManualInteractionRequired'));

    Queue::assertNotPushed(RunDecisionAgent::class);
});
