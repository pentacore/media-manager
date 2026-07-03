<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Cache\Services\WhisparrCache;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\ServiceWarning;
use App\Services\Library\InterventionCounter;
use App\Services\Webhook\AbstractWebhookHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class WhisparrWebhookHandler extends AbstractWebhookHandler
{
    protected function serviceSlug(): string
    {
        return 'whisparr';
    }

    public function handle(WebhookEvent $webhookEvent): void
    {
        $payload = $webhookEvent->payload;
        $eventType = $payload['eventType'] ?? null;

        match ($eventType) {
            'Test' => $this->handleTest($webhookEvent, $payload),
            'Grab' => $this->handleGrab($webhookEvent, $payload),
            'Download' => $this->handleDownload($webhookEvent, $payload),
            'Rename' => $this->handleRename($webhookEvent, $payload),
            'MovieAdded' => $this->handleItemAdded($webhookEvent, $payload),
            'SeriesAdd' => $this->handleItemAdded($webhookEvent, $payload),
            'MovieDelete' => $this->handleItemDeleted($webhookEvent, $payload),
            'SeriesDelete' => $this->handleItemDeleted($webhookEvent, $payload),
            'MovieFileDelete' => $this->handleFileDeleted($webhookEvent, $payload),
            'EpisodeFileDelete' => $this->handleFileDeleted($webhookEvent, $payload),
            'ManualInteractionRequired' => $this->handleManualInteractionRequired($webhookEvent, $payload),
            'Health' => $this->handleHealth($webhookEvent, $payload, 'health'),
            'HealthRestored' => $this->handleHealth($webhookEvent, $payload, 'health_restored'),
            'ApplicationUpdate' => $this->handleApplicationUpdate($webhookEvent, $payload),
            default => Log::info('WhisparrWebhookHandler: ignoring event', [
                'webhook_event_id' => $webhookEvent->id,
                'event_type' => $eventType,
            ]),
        };

        $webhookEvent->markProcessed();

        if ($webhookEvent->serviceConnection !== null) {
            new WhisparrCache($webhookEvent->serviceConnection)->bustAll();
        }
    }

    /**
     * Whisparr v3 sends `movie`, v2/Eros sends `series`. Return whichever is
     * present as a normalized [title, id] pair so handlers stay version-agnostic.
     *
     * @param  array<string, mixed>  $payload
     * @return array{title: string, id: int|null}
     */
    private function item(array $payload): array
    {
        $node = is_array($payload['movie'] ?? null)
            ? $payload['movie']
            : (is_array($payload['series'] ?? null) ? $payload['series'] : []);

        return [
            'title' => (string) ($node['title'] ?? 'Unknown item'),
            'id' => isset($node['id']) ? (int) $node['id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleTest(WebhookEvent $webhookEvent, array $payload): void
    {
        $this->logActivity($webhookEvent, 'test', 'Whisparr webhook test received.', metadata: [
            'instance_name' => $payload['instanceName'] ?? null,
            'application_url' => $payload['applicationUrl'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleGrab(WebhookEvent $webhookEvent, array $payload): void
    {
        $item = $this->item($payload);
        $this->logActivity($webhookEvent, 'grab', sprintf('Whisparr grabbed "%s".', $item['title']), metadata: [
            'release' => $payload['release'] ?? null,
        ], subjectId: $item['id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleDownload(WebhookEvent $webhookEvent, array $payload): void
    {
        $item = $this->item($payload);
        $this->logActivity($webhookEvent, 'download', sprintf('Whisparr imported "%s".', $item['title']), metadata: [
            'is_upgrade' => $payload['isUpgrade'] ?? null,
        ], subjectId: $item['id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleRename(WebhookEvent $webhookEvent, array $payload): void
    {
        $item = $this->item($payload);
        $this->logActivity($webhookEvent, 'rename', sprintf('Whisparr renamed files for "%s".', $item['title']), subjectId: $item['id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleItemAdded(WebhookEvent $webhookEvent, array $payload): void
    {
        $item = $this->item($payload);
        $action = isset($payload['series']) ? 'series_added' : 'movie_added';
        $this->logActivity($webhookEvent, $action, sprintf('Whisparr added "%s".', $item['title']), subjectId: $item['id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleItemDeleted(WebhookEvent $webhookEvent, array $payload): void
    {
        $item = $this->item($payload);
        $action = isset($payload['series']) ? 'series_deleted' : 'movie_deleted';
        $this->logActivity($webhookEvent, $action, sprintf('Whisparr deleted "%s".', $item['title']), metadata: [
            'deleted_files' => $payload['deletedFiles'] ?? null,
        ], subjectId: $item['id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleFileDeleted(WebhookEvent $webhookEvent, array $payload): void
    {
        $item = $this->item($payload);
        $action = isset($payload['series']) ? 'episode_file_deleted' : 'movie_file_deleted';
        $this->logActivity($webhookEvent, $action, sprintf('Whisparr deleted a file for "%s".', $item['title']), metadata: [
            'delete_reason' => $payload['deleteReason'] ?? null,
        ], subjectId: $item['id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleManualInteractionRequired(WebhookEvent $webhookEvent, array $payload): void
    {
        $item = $this->item($payload);
        $download = is_array($payload['downloadInfo'] ?? null) ? $payload['downloadInfo'] : [];

        $this->logActivity($webhookEvent, 'manual_interaction_required', sprintf('Whisparr needs manual import for "%s".', $item['title']), metadata: [
            'download_id' => $payload['downloadId'] ?? ($download['downloadId'] ?? null),
            'download_client' => $payload['downloadClient'] ?? null,
        ], subjectId: $item['id']);

        resolve(InterventionCounter::class)->recompute();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleHealth(WebhookEvent $webhookEvent, array $payload, string $kind): void
    {
        $message = (string) ($payload['message'] ?? 'Unknown health event');
        $level = (string) ($payload['level'] ?? 'ok');

        $this->logActivity($webhookEvent, $kind, $message, metadata: [
            'level' => $payload['level'] ?? null,
            'type' => $payload['type'] ?? null,
            'wiki_url' => $payload['wikiUrl'] ?? null,
        ]);

        if ($kind !== 'health' || ! in_array($level, ['warning', 'error'], true)) {
            return;
        }

        $admins = User::query()->where('role', UserRole::Admin)->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ServiceWarning(
                service: 'whisparr',
                title: (string) ($payload['type'] ?? 'Whisparr health'),
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

        $this->logActivity($webhookEvent, 'updated', sprintf(
            'Whisparr updated from %s to %s.',
            $previousVersion ?? 'unknown',
            $newVersion ?? 'unknown',
        ), metadata: [
            'previous_version' => $previousVersion,
            'new_version' => $newVersion,
            'message' => $payload['message'] ?? null,
        ]);
    }
}
