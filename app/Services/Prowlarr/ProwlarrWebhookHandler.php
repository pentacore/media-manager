<?php

declare(strict_types=1);

namespace App\Services\Prowlarr;

use App\Enums\WebhookHandlingStatus;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\WebhookEvent;
use App\Services\Webhook\AbstractArrWebhookHandler;

class ProwlarrWebhookHandler extends AbstractArrWebhookHandler
{
    protected function serviceSlug(): string
    {
        return 'prowlarr';
    }

    public function handle(WebhookEvent $webhookEvent): WebhookHandlingStatus
    {
        $payload = $webhookEvent->payload;
        $eventType = $payload['eventType'] ?? null;

        $status = WebhookHandlingStatus::Handled;

        match ($eventType) {
            'Test' => $this->handleTest($webhookEvent, $payload),
            'Health' => $this->handleHealth($webhookEvent, $payload, 'health'),
            'HealthRestored' => $this->handleHealth($webhookEvent, $payload, 'health_restored'),
            'ApplicationUpdate' => $this->handleApplicationUpdate($webhookEvent, $payload),
            default => $status = $this->ignore($webhookEvent, $eventType),
        };

        return $status;
    }

    /**
     * Re-ping so the connection's stored health state catches up immediately
     * instead of waiting for the next scheduled tick.
     */
    protected function afterHealthLogged(WebhookEvent $webhookEvent): void
    {
        dispatch(new PingServiceHealth($webhookEvent->serviceConnection));
    }

    /**
     * Surface the new version on the dashboard immediately rather than
     * waiting for the next scheduled version tick.
     */
    protected function afterApplicationUpdateLogged(WebhookEvent $webhookEvent): void
    {
        dispatch(new FetchLatestServiceVersion($webhookEvent->serviceConnection));
    }
}
