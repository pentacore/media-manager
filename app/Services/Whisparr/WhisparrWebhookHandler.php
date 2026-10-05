<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Cache\Services\WhisparrCache;
use App\Enums\WebhookHandlingStatus;
use App\Models\WebhookEvent;
use App\Services\Library\InterventionCounter;
use App\Services\Webhook\AbstractArrWebhookHandler;

class WhisparrWebhookHandler extends AbstractArrWebhookHandler
{
    protected function serviceSlug(): string
    {
        return 'whisparr';
    }

    public function handle(WebhookEvent $webhookEvent): WebhookHandlingStatus
    {
        $payload = $webhookEvent->payload;
        $eventType = $payload['eventType'] ?? null;

        $status = WebhookHandlingStatus::Handled;

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
            default => $status = $this->ignore($webhookEvent, $eventType),
        };

        if ($webhookEvent->serviceConnection !== null) {
            new WhisparrCache($webhookEvent->serviceConnection)->bustAll();
        }

        return $status;
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
}
