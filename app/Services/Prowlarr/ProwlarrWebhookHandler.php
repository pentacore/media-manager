<?php

declare(strict_types=1);

namespace App\Services\Prowlarr;

use App\Enums\UserRole;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\ServiceWarning;
use App\Services\Webhook\AbstractWebhookHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProwlarrWebhookHandler extends AbstractWebhookHandler
{
    protected function serviceSlug(): string
    {
        return 'prowlarr';
    }

    public function handle(WebhookEvent $webhookEvent): void
    {
        $payload = $webhookEvent->payload;
        $eventType = $payload['eventType'] ?? null;

        match ($eventType) {
            'Test' => $this->handleTest($webhookEvent, $payload),
            'Health' => $this->handleHealth($webhookEvent, $payload, 'health'),
            'HealthRestored' => $this->handleHealth($webhookEvent, $payload, 'health_restored'),
            'ApplicationUpdate' => $this->handleApplicationUpdate($webhookEvent, $payload),
            default => Log::info('ProwlarrWebhookHandler: ignoring event', [
                'webhook_event_id' => $webhookEvent->id,
                'event_type' => $eventType,
            ]),
        };

        $webhookEvent->markProcessed();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleTest(WebhookEvent $webhookEvent, array $payload): void
    {
        $this->logActivity(
            $webhookEvent,
            'test',
            'Prowlarr webhook test received.',
            metadata: [
                'instance_name' => $payload['instanceName'] ?? null,
                'application_url' => $payload['applicationUrl'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleHealth(WebhookEvent $webhookEvent, array $payload, string $kind): void
    {
        $message = (string) ($payload['message'] ?? 'Unknown health event');
        $level = (string) ($payload['level'] ?? 'ok');

        $this->logActivity(
            $webhookEvent,
            $kind,
            $message,
            metadata: [
                'level' => $payload['level'] ?? null,
                'type' => $payload['type'] ?? null,
                'wiki_url' => $payload['wikiUrl'] ?? null,
            ],
        );

        // Re-ping so the connection's stored health state catches up immediately
        // instead of waiting for the next scheduled tick.
        dispatch(new PingServiceHealth($webhookEvent->serviceConnection));

        if ($kind !== 'health' || ! in_array($level, ['warning', 'error'], true)) {
            return;
        }

        $admins = User::query()->where('role', UserRole::Admin)->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ServiceWarning(
                service: 'prowlarr',
                title: (string) ($payload['type'] ?? 'Prowlarr health'),
                message: $message,
                level: $level,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleApplicationUpdate(WebhookEvent $webhookEvent, array $payload): void
    {
        $previousVersion = $payload['previousVersion'] ?? null;
        $newVersion = $payload['newVersion'] ?? null;

        $this->logActivity(
            $webhookEvent,
            'updated',
            sprintf(
                'Prowlarr updated from %s to %s.',
                $previousVersion ?? 'unknown',
                $newVersion ?? 'unknown',
            ),
            metadata: [
                'previous_version' => $previousVersion,
                'new_version' => $newVersion,
                'message' => $payload['message'] ?? null,
            ],
        );

        // Surface the new version on the dashboard immediately rather than
        // waiting for the next scheduled version tick.
        dispatch(new FetchLatestServiceVersion($webhookEvent->serviceConnection));
    }
}
