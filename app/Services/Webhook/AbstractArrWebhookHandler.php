<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Enums\ServiceType;
use App\Models\WebhookEvent;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\AdminNotifier;

/**
 * The housekeeping events every arr-family service (Sonarr, Radarr,
 * Whisparr, Prowlarr) sends the same way: Test, Health, HealthRestored and
 * ApplicationUpdate. Each becomes a `webhook.{slug}.test|health|
 * health_restored|updated` activity row; a live Health warning or error also
 * notifies the admins. Prowlarr adds its re-ping and version fetch through
 * the after* hooks.
 */
abstract class AbstractArrWebhookHandler extends AbstractWebhookHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleTest(WebhookEvent $webhookEvent, array $payload): void
    {
        $this->logActivity(
            $webhookEvent,
            'test',
            sprintf('%s webhook test received.', $this->serviceLabel()),
            metadata: [
                'instance_name' => $payload['instanceName'] ?? null,
                'application_url' => $payload['applicationUrl'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  'health'|'health_restored'  $kind
     */
    protected function handleHealth(WebhookEvent $webhookEvent, array $payload, string $kind): void
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

        $this->afterHealthLogged($webhookEvent);

        // health_restored is informational; only the live `Health` event
        // with a non-ok level deserves a notification.
        if ($kind !== 'health' || ! in_array($level, ['warning', 'error'], true)) {
            return;
        }

        $this->adminNotifier()->send(new ServiceWarning(
            service: $this->serviceSlug(),
            title: (string) ($payload['type'] ?? sprintf('%s health', $this->serviceLabel())),
            message: $message,
            level: $level,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleApplicationUpdate(WebhookEvent $webhookEvent, array $payload): void
    {
        $previousVersion = $payload['previousVersion'] ?? null;
        $newVersion = $payload['newVersion'] ?? null;

        $this->logActivity(
            $webhookEvent,
            'updated',
            sprintf(
                '%s updated from %s to %s.',
                $this->serviceLabel(),
                $previousVersion ?? 'unknown',
                $newVersion ?? 'unknown',
            ),
            metadata: [
                'previous_version' => $previousVersion,
                'new_version' => $newVersion,
                'message' => $payload['message'] ?? null,
            ],
        );

        $this->afterApplicationUpdateLogged($webhookEvent);
    }

    /**
     * Runs after a Health or HealthRestored row is written, before any
     * notification.
     */
    protected function afterHealthLogged(WebhookEvent $webhookEvent): void {}

    /**
     * Runs after an ApplicationUpdate row is written.
     */
    protected function afterApplicationUpdateLogged(WebhookEvent $webhookEvent): void {}

    /**
     * A handler that injects its notifier returns that instance instead.
     */
    protected function adminNotifier(): AdminNotifier
    {
        return resolve(AdminNotifier::class);
    }

    private function serviceLabel(): string
    {
        return ServiceType::from($this->serviceSlug())->label();
    }
}
