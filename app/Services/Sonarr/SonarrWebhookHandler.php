<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Models\WebhookEvent;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Webhook\WebhookHandler;
use Illuminate\Support\Facades\Log;

class SonarrWebhookHandler implements WebhookHandler
{
    public function __construct(private readonly ActionOrchestrator $actionOrchestrator) {}

    public function handle(WebhookEvent $webhookEvent): void
    {
        $payload = $webhookEvent->payload;
        $eventType = $payload['eventType'] ?? null;

        match ($eventType) {
            'Download' => $this->handleDownload($webhookEvent, $payload),
            default => Log::info('SonarrWebhookHandler: ignoring event', [
                'webhook_event_id' => $webhookEvent->id,
                'event_type' => $eventType,
            ]),
        };

        $webhookEvent->markProcessed();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleDownload(WebhookEvent $webhookEvent, array $payload): void
    {
        $this->actionOrchestrator->dispatch(
            type: 'emby_library_scan',
            sourceService: 'sonarr',
            targetService: 'emby',
            payload: [
                'trigger' => 'sonarr_download',
                'series_title' => $payload['series']['title'] ?? null,
            ],
            webhookEvent: $webhookEvent,
        );
    }
}
