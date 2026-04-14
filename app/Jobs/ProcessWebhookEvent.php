<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServiceType;
use App\Models\WebhookEvent;
use App\Services\Emby\EmbyWebhookHandler;
use App\Services\Sonarr\SonarrWebhookHandler;
use App\Services\Webhook\WebhookHandler;
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

            return;
        }

        $handler->handle($this->webhookEvent);
    }

    private function resolveHandler(ServiceType $serviceType): ?WebhookHandler
    {
        $class = match ($serviceType) {
            ServiceType::Emby => EmbyWebhookHandler::class,
            ServiceType::Sonarr => SonarrWebhookHandler::class,
            default => null,
        };

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        /** @var WebhookHandler $embyWebhookHandler */
        $embyWebhookHandler = resolve($class);

        return $embyWebhookHandler;
    }
}
