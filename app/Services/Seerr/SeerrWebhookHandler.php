<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Models\ActivityLog;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Webhook\WebhookHandler;
use Illuminate\Support\Facades\Log;

class SeerrWebhookHandler implements WebhookHandler
{
    public function __construct(private readonly ActionOrchestrator $actionOrchestrator) {}

    public function handle(WebhookEvent $webhookEvent): void
    {
        $payload = $webhookEvent->payload;
        $notificationType = $payload['notification_type'] ?? null;

        match ($notificationType) {
            'TEST_NOTIFICATION' => $this->handleTest($webhookEvent, $payload),
            'MEDIA_PENDING' => $this->handleRequestLifecycle($webhookEvent, $payload, 'request_pending', 'Request submitted'),
            'MEDIA_APPROVED' => $this->handleRequestLifecycle($webhookEvent, $payload, 'request_approved', 'Request approved'),
            'MEDIA_AUTO_APPROVED' => $this->handleRequestLifecycle($webhookEvent, $payload, 'request_approved', 'Request auto-approved'),
            'MEDIA_DECLINED' => $this->handleRequestLifecycle($webhookEvent, $payload, 'request_declined', 'Request declined'),
            'MEDIA_AVAILABLE' => $this->handleMediaAvailable($webhookEvent, $payload),
            'MEDIA_FAILED' => $this->handleRequestLifecycle($webhookEvent, $payload, 'request_failed', 'Request processing failed'),
            'ISSUE_CREATED' => $this->handleIssue($webhookEvent, $payload, 'issue_created', 'Issue reported'),
            'ISSUE_COMMENT' => $this->handleIssue($webhookEvent, $payload, 'issue_comment', 'Issue comment'),
            'ISSUE_RESOLVED' => $this->handleIssue($webhookEvent, $payload, 'issue_resolved', 'Issue resolved'),
            'ISSUE_REOPENED' => $this->handleIssue($webhookEvent, $payload, 'issue_reopened', 'Issue reopened'),
            default => Log::info('SeerrWebhookHandler: ignoring notification', [
                'webhook_event_id' => $webhookEvent->id,
                'notification_type' => $notificationType,
            ]),
        };

        $webhookEvent->markProcessed();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleTest(WebhookEvent $webhookEvent, array $payload): void
    {
        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.seerr.test',
            'subject_type' => null,
            'subject_id' => null,
            'description' => (string) ($payload['message'] ?? 'Seerr test notification received.'),
            'metadata' => ['subject' => $payload['subject'] ?? null],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleRequestLifecycle(WebhookEvent $webhookEvent, array $payload, string $action, string $verb): void
    {
        $subject = (string) ($payload['subject'] ?? 'Unknown');
        $username = $payload['request']['requestedBy_username'] ?? null;

        $description = $username !== null
            ? sprintf('%s: "%s" (by %s)', $verb, $subject, $username)
            : sprintf('%s: "%s"', $verb, $subject);

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.seerr.'.$action,
            'subject_type' => null,
            'subject_id' => isset($payload['request']['request_id']) ? (int) $payload['request']['request_id'] : null,
            'description' => $description,
            'metadata' => [
                'subject' => $subject,
                'media_type' => $payload['media']['media_type'] ?? null,
                'tmdb_id' => $payload['media']['tmdbId'] ?? null,
                'tvdb_id' => $payload['media']['tvdbId'] ?? null,
                'request_id' => $payload['request']['request_id'] ?? null,
                'requester' => $username,
                'message' => $payload['message'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleMediaAvailable(WebhookEvent $webhookEvent, array $payload): void
    {
        $this->handleRequestLifecycle($webhookEvent, $payload, 'media_available', 'Media available');

        // When Seerr reports media is available, it just appeared in Emby's library via
        // the corresponding *arr service. Refresh Emby so it picks up the file right away.
        $this->actionOrchestrator->dispatch(
            type: 'emby_library_scan',
            sourceService: 'seerr',
            targetService: 'emby',
            payload: [
                'trigger' => 'seerr_media_available',
                'subject' => $payload['subject'] ?? null,
            ],
            webhookEvent: $webhookEvent,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleIssue(WebhookEvent $webhookEvent, array $payload, string $action, string $verb): void
    {
        $subject = (string) ($payload['subject'] ?? 'Unknown');
        $reporter = $payload['request']['requestedBy_username'] ?? null;

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.seerr.'.$action,
            'subject_type' => null,
            'subject_id' => null,
            'description' => $reporter !== null
                ? sprintf('%s on "%s" (by %s)', $verb, $subject, $reporter)
                : sprintf('%s on "%s"', $verb, $subject),
            'metadata' => [
                'subject' => $subject,
                'issue' => $payload['issue'] ?? null,
                'comment' => $payload['comment'] ?? null,
                'reporter' => $reporter,
            ],
        ]);
    }
}
