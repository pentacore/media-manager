<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServiceType;
use App\Models\WebhookEvent;
use App\Services\Emby\EmbyWebhookHandler;
use App\Services\Prowlarr\ProwlarrWebhookHandler;
use App\Services\Radarr\RadarrWebhookHandler;
use App\Services\Sabnzbd\SabnzbdWebhookHandler;
use App\Services\Seerr\SeerrWebhookHandler;
use App\Services\Sonarr\SonarrWebhookHandler;
use App\Services\Webhook\WebhookHandler;
use App\Services\Whisparr\WhisparrWebhookHandler;
use App\Settings\WebhookSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public WebhookEvent $webhookEvent) {}

    public function handle(): void
    {
        $this->webhookEvent->refresh();

        // Idempotency: if the handler already ran to completion, don't retry.
        if ($this->webhookEvent->processed_at !== null) {
            Log::info('ProcessWebhookEvent: already processed, skipping', [
                'webhook_event_id' => $this->webhookEvent->id,
            ]);

            return;
        }

        $this->webhookEvent->loadMissing('serviceConnection');
        $connection = $this->webhookEvent->serviceConnection;

        if ($connection === null) {
            Log::warning('ProcessWebhookEvent: webhook event has no service connection', [
                'webhook_event_id' => $this->webhookEvent->id,
            ]);

            return;
        }

        $handler = $this->resolveHandler($connection->type);

        if (! $handler instanceof WebhookHandler) {
            Log::info('ProcessWebhookEvent: no handler registered for service type', [
                'webhook_event_id' => $this->webhookEvent->id,
                'service_type' => $connection->type->value,
            ]);
            $this->discardIfCaptureDisabled();

            return;
        }

        $handler->handle($this->webhookEvent);

        $this->discardIfCaptureDisabled();
    }

    /**
     * Drop the persisted event row when admins have turned capture off.
     * Handlers always run against a stored model (so dedupe and retries
     * work) and the row is trimmed afterwards to keep the log table from
     * growing without bound.
     */
    private function discardIfCaptureDisabled(): void
    {
        if (resolve(WebhookSettings::class)->captureEnabled()) {
            return;
        }

        $this->webhookEvent->delete();
    }

    private function resolveHandler(ServiceType $serviceType): ?WebhookHandler
    {
        $class = match ($serviceType) {
            ServiceType::Emby => EmbyWebhookHandler::class,
            ServiceType::Sonarr => SonarrWebhookHandler::class,
            ServiceType::Radarr => RadarrWebhookHandler::class,
            ServiceType::Seerr => SeerrWebhookHandler::class,
            ServiceType::Prowlarr => ProwlarrWebhookHandler::class,
            ServiceType::SABnzbd => SabnzbdWebhookHandler::class,
            ServiceType::Whisparr => WhisparrWebhookHandler::class,
        };

        if (! class_exists($class)) {
            return null;
        }

        /** @var WebhookHandler $handler */
        $handler = resolve($class);

        return $handler;
    }
}
