<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServiceType;
use App\Enums\WebhookHandlingStatus;
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
use Throwable;

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

        if (! $this->claim()) {
            Log::info('ProcessWebhookEvent: already processed or claimed by another worker, skipping', [
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
            $this->webhookEvent->update(['handling_status' => WebhookHandlingStatus::NoHandler]);
            $this->discardIfCaptureDisabled();

            return;
        }

        $webhookHandlingStatus = $handler->handle($this->webhookEvent);
        $this->webhookEvent->update(['handling_status' => $webhookHandlingStatus]);

        $this->discardIfCaptureDisabled();
    }

    /**
     * Atomically claim the event for this worker. The previous read-check on
     * `processed_at` was not atomic: a redelivered/duplicate job could pass
     * the check while another worker was mid-handle and run the side effects
     * twice. Only the worker whose conditional update flips the status to
     * Processing proceeds; a retry attempt may reclaim a row left in
     * Processing by a worker that died mid-run.
     */
    private function claim(): bool
    {
        $claimed = WebhookEvent::query()
            ->whereKey($this->webhookEvent->id)
            ->whereNull('processed_at')
            ->whereNull('handling_status')
            ->update(['handling_status' => WebhookHandlingStatus::Processing->value]);

        if ($claimed === 1) {
            $this->webhookEvent->refresh();

            return true;
        }

        $this->webhookEvent->refresh();

        return $this->webhookEvent->processed_at === null
            && $this->webhookEvent->handling_status === WebhookHandlingStatus::Processing
            && $this->attempts() > 1;
    }

    /**
     * Record the terminal failure after retries are exhausted, if the event
     * row still exists (capture-off trimming may have removed it).
     */
    public function failed(?Throwable $throwable): void
    {
        if (WebhookEvent::query()->whereKey($this->webhookEvent->id)->exists()) {
            WebhookEvent::query()
                ->whereKey($this->webhookEvent->id)
                ->update(['handling_status' => WebhookHandlingStatus::Failed->value]);
        }
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
