<?php

declare(strict_types=1);

namespace App\Services\Sabnzbd;

use App\Cache\Services\SabnzbdCache;
use App\Models\WebhookEvent;
use App\Services\Webhook\AbstractWebhookHandler;
use Illuminate\Support\Facades\Log;

class SabnzbdWebhookHandler extends AbstractWebhookHandler
{
    protected function serviceSlug(): string
    {
        return 'sabnzbd';
    }

    public function handle(WebhookEvent $webhookEvent): void
    {
        $payload = $webhookEvent->payload;
        $eventType = (string) ($payload['eventType'] ?? '');

        match ($eventType) {
            'complete' => $this->handleComplete($webhookEvent, $payload),
            'failed' => $this->handleFailed($webhookEvent, $payload),
            'startup' => $this->handleSimple($webhookEvent, $payload, 'startup', 'SABnzbd starting up.'),
            'pause' => $this->handleSimple($webhookEvent, $payload, 'queue.paused', 'SABnzbd queue paused.'),
            'resume' => $this->handleSimple($webhookEvent, $payload, 'queue.resumed', 'SABnzbd queue resumed.'),
            'queue_done' => $this->handleSimple($webhookEvent, $payload, 'queue.done', 'SABnzbd queue finished.'),
            'warning' => $this->handleAlert($webhookEvent, $payload, 'warning'),
            'error' => $this->handleAlert($webhookEvent, $payload, 'error'),
            'disk_full' => $this->handleAlert($webhookEvent, $payload, 'disk_full'),
            default => Log::info('SabnzbdWebhookHandler: ignoring event', [
                'webhook_event_id' => $webhookEvent->id,
                'event_type' => $eventType,
            ]),
        };

        $webhookEvent->markProcessed();

        if ($webhookEvent->serviceConnection !== null) {
            new SabnzbdCache($webhookEvent->serviceConnection)->bustAll();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleComplete(WebhookEvent $webhookEvent, array $payload): void
    {
        $name = (string) ($payload['name'] ?? $payload['title'] ?? 'Unknown download');

        // Mirror the action key the legacy PollSabnzbdHistory writes so
        // the activity-log humanizer renders both consistently.
        $this->logActivity(
            $webhookEvent,
            'download.completed',
            sprintf('SABnzbd finished "%s".', $name),
            metadata: $this->commonMetadata($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleFailed(WebhookEvent $webhookEvent, array $payload): void
    {
        $name = (string) ($payload['name'] ?? $payload['title'] ?? 'Unknown download');

        $this->logActivity(
            $webhookEvent,
            'download.failed',
            sprintf('SABnzbd failed to download "%s": %s', $name, $payload['message'] ?? 'no message'),
            metadata: $this->commonMetadata($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleSimple(WebhookEvent $webhookEvent, array $payload, string $action, string $description): void
    {
        $this->logActivity(
            $webhookEvent,
            $action,
            $description,
            metadata: $this->commonMetadata($payload),
        );
    }

    /**
     * Warning / error / disk_full all share the same shape and produce
     * the same activity entry; the per-user notification dispatch lives
     * in Phase D and reads this same severity tag from metadata.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleAlert(WebhookEvent $webhookEvent, array $payload, string $severity): void
    {
        $title = (string) ($payload['title'] ?? ucfirst($severity));
        $message = (string) ($payload['message'] ?? '');

        $this->logActivity(
            $webhookEvent,
            sprintf('alert.%s', $severity),
            sprintf('SABnzbd %s: %s', $severity, $title),
            metadata: [
                ...$this->commonMetadata($payload),
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function commonMetadata(array $payload): array
    {
        return [
            'hostname' => $payload['hostname'] ?? null,
            'version' => $payload['version'] ?? null,
            'category' => $payload['category'] ?? null,
            'name' => $payload['name'] ?? null,
        ];
    }
}
