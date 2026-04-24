<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Models\ActivityLog;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Webhook\WebhookHandler;
use Illuminate\Support\Facades\Log;

class RadarrWebhookHandler implements WebhookHandler
{
    public function __construct(private readonly ActionOrchestrator $actionOrchestrator) {}

    public function handle(WebhookEvent $webhookEvent): void
    {
        $payload = $webhookEvent->payload;
        $eventType = $payload['eventType'] ?? null;

        match ($eventType) {
            'Test' => $this->handleTest($webhookEvent, $payload),
            'Grab' => $this->handleGrab($webhookEvent, $payload),
            'Download' => $this->handleDownload($webhookEvent, $payload),
            'Rename' => $this->handleRename($webhookEvent, $payload),
            'MovieAdded' => $this->handleMovieAdded($webhookEvent, $payload),
            'MovieDelete' => $this->handleMovieDelete($webhookEvent, $payload),
            'MovieFileDelete' => $this->handleMovieFileDelete($webhookEvent, $payload),
            'Health' => $this->handleHealth($webhookEvent, $payload, 'health'),
            'HealthRestored' => $this->handleHealth($webhookEvent, $payload, 'health_restored'),
            'ApplicationUpdate' => $this->handleApplicationUpdate($webhookEvent, $payload),
            default => Log::info('RadarrWebhookHandler: ignoring event', [
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
        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.test',
            'subject_type' => null,
            'subject_id' => null,
            'description' => 'Radarr webhook test received.',
            'metadata' => [
                'instance_name' => $payload['instanceName'] ?? null,
                'application_url' => $payload['applicationUrl'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleGrab(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.grab',
            'subject_type' => null,
            'subject_id' => $payload['movie']['id'] ?? null,
            'description' => sprintf('Radarr grabbed "%s".', $movieTitle),
            'metadata' => [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'release' => $payload['release'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleDownload(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.download',
            'subject_type' => null,
            'subject_id' => $payload['movie']['id'] ?? null,
            'description' => sprintf('Radarr imported "%s".', $movieTitle),
            'metadata' => [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'movie_file' => $payload['movieFile'] ?? null,
                'is_upgrade' => $payload['isUpgrade'] ?? null,
            ],
        ]);

        $this->actionOrchestrator->dispatch(
            type: 'emby_library_scan',
            sourceService: 'radarr',
            targetService: 'emby',
            payload: [
                'trigger' => 'radarr_download',
                'movie_title' => $movieTitle,
            ],
            webhookEvent: $webhookEvent,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleRename(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.rename',
            'subject_type' => null,
            'subject_id' => $payload['movie']['id'] ?? null,
            'description' => sprintf('Radarr renamed files for "%s".', $movieTitle),
            'metadata' => [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'renamed_movie_files' => $payload['renamedMovieFiles'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleMovieAdded(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.movie_added',
            'subject_type' => null,
            'subject_id' => $payload['movie']['id'] ?? null,
            'description' => sprintf('Radarr added movie "%s".', $movieTitle),
            'metadata' => [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'folder_path' => $payload['movie']['folderPath'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleMovieDelete(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.movie_deleted',
            'subject_type' => null,
            'subject_id' => $payload['movie']['id'] ?? null,
            'description' => sprintf('Radarr deleted movie "%s".', $movieTitle),
            'metadata' => [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'deleted_files' => $payload['deletedFiles'] ?? null,
            ],
        ]);

        $this->actionOrchestrator->dispatch(
            type: 'emby_library_scan',
            sourceService: 'radarr',
            targetService: 'emby',
            payload: [
                'trigger' => 'radarr_movie_deleted',
                'movie_title' => $movieTitle,
            ],
            webhookEvent: $webhookEvent,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleMovieFileDelete(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.movie_file_deleted',
            'subject_type' => null,
            'subject_id' => $payload['movie']['id'] ?? null,
            'description' => sprintf('Radarr deleted a movie file for "%s".', $movieTitle),
            'metadata' => [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'movie_file' => $payload['movieFile'] ?? null,
                'delete_reason' => $payload['deleteReason'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleHealth(WebhookEvent $webhookEvent, array $payload, string $kind): void
    {
        $message = (string) ($payload['message'] ?? 'Unknown health event');

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.'.$kind,
            'subject_type' => null,
            'subject_id' => null,
            'description' => $message,
            'metadata' => [
                'level' => $payload['level'] ?? null,
                'type' => $payload['type'] ?? null,
                'wiki_url' => $payload['wikiUrl'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleApplicationUpdate(WebhookEvent $webhookEvent, array $payload): void
    {
        $previousVersion = $payload['previousVersion'] ?? null;
        $newVersion = $payload['newVersion'] ?? null;

        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => 'webhook.radarr.updated',
            'subject_type' => null,
            'subject_id' => null,
            'description' => sprintf(
                'Radarr updated from %s to %s.',
                $previousVersion ?? 'unknown',
                $newVersion ?? 'unknown',
            ),
            'metadata' => [
                'previous_version' => $previousVersion,
                'new_version' => $newVersion,
                'message' => $payload['message'] ?? null,
            ],
        ]);
    }
}
