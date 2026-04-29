<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Cache\Services\RadarrCache;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Library\InterventionCounter;
use App\Services\Webhook\AbstractWebhookHandler;
use Illuminate\Support\Facades\Log;

class RadarrWebhookHandler extends AbstractWebhookHandler
{
    public function __construct(private readonly ActionOrchestrator $actionOrchestrator) {}

    protected function serviceSlug(): string
    {
        return 'radarr';
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
            'MovieAdded' => $this->handleMovieAdded($webhookEvent, $payload),
            'MovieDelete' => $this->handleMovieDelete($webhookEvent, $payload),
            'MovieFileDelete' => $this->handleMovieFileDelete($webhookEvent, $payload),
            'ManualInteractionRequired' => $this->handleManualInteractionRequired($webhookEvent, $payload),
            'Health' => $this->handleHealth($webhookEvent, $payload, 'health'),
            'HealthRestored' => $this->handleHealth($webhookEvent, $payload, 'health_restored'),
            'ApplicationUpdate' => $this->handleApplicationUpdate($webhookEvent, $payload),
            default => Log::info('RadarrWebhookHandler: ignoring event', [
                'webhook_event_id' => $webhookEvent->id,
                'event_type' => $eventType,
            ]),
        };

        $webhookEvent->markProcessed();

        if ($webhookEvent->serviceConnection !== null) {
            new RadarrCache($webhookEvent->serviceConnection)->bustAll();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleTest(WebhookEvent $webhookEvent, array $payload): void
    {
        $this->logActivity(
            $webhookEvent,
            'test',
            'Radarr webhook test received.',
            metadata: [
                'instance_name' => $payload['instanceName'] ?? null,
                'application_url' => $payload['applicationUrl'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleGrab(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        $this->logActivity(
            $webhookEvent,
            'grab',
            sprintf('Radarr grabbed "%s".', $movieTitle),
            metadata: [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'release' => $payload['release'] ?? null,
            ],
            subjectId: $payload['movie']['id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleDownload(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        $this->logActivity(
            $webhookEvent,
            'download',
            sprintf('Radarr imported "%s".', $movieTitle),
            metadata: [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'movie_file' => $payload['movieFile'] ?? null,
                'is_upgrade' => $payload['isUpgrade'] ?? null,
            ],
            subjectId: $payload['movie']['id'] ?? null,
        );

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

        $this->logActivity(
            $webhookEvent,
            'rename',
            sprintf('Radarr renamed files for "%s".', $movieTitle),
            metadata: [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'renamed_movie_files' => $payload['renamedMovieFiles'] ?? null,
            ],
            subjectId: $payload['movie']['id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleMovieAdded(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        $this->logActivity(
            $webhookEvent,
            'movie_added',
            sprintf('Radarr added movie "%s".', $movieTitle),
            metadata: [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'folder_path' => $payload['movie']['folderPath'] ?? null,
            ],
            subjectId: $payload['movie']['id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleMovieDelete(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';

        $this->logActivity(
            $webhookEvent,
            'movie_deleted',
            sprintf('Radarr deleted movie "%s".', $movieTitle),
            metadata: [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'deleted_files' => $payload['deletedFiles'] ?? null,
            ],
            subjectId: $payload['movie']['id'] ?? null,
        );

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

        $this->logActivity(
            $webhookEvent,
            'movie_file_deleted',
            sprintf('Radarr deleted a movie file for "%s".', $movieTitle),
            metadata: [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'movie_file' => $payload['movieFile'] ?? null,
                'delete_reason' => $payload['deleteReason'] ?? null,
            ],
            subjectId: $payload['movie']['id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleManualInteractionRequired(WebhookEvent $webhookEvent, array $payload): void
    {
        $movieTitle = $payload['movie']['title'] ?? 'Unknown movie';
        $download = is_array($payload['downloadInfo'] ?? null) ? $payload['downloadInfo'] : [];
        $messages = is_array($payload['downloadStatusMessages'] ?? null) ? $payload['downloadStatusMessages'] : [];

        $this->logActivity(
            $webhookEvent,
            'manual_interaction_required',
            sprintf('Radarr needs manual import for "%s".', $movieTitle),
            metadata: [
                'movie_id' => $payload['movie']['id'] ?? null,
                'tmdb_id' => $payload['movie']['tmdbId'] ?? null,
                'imdb_id' => $payload['movie']['imdbId'] ?? null,
                'download_id' => $payload['downloadId'] ?? ($download['downloadId'] ?? null),
                'download_client' => $payload['downloadClient'] ?? null,
                'download_title' => $download['title'] ?? null,
                'release_size' => $download['size'] ?? null,
                'status_messages' => $messages,
            ],
            subjectId: $payload['movie']['id'] ?? null,
        );

        // The library activity badge needs to reflect the new stuck import
        // immediately — without this it would only update on the next
        // scheduled poll (5 min) or page reload.
        resolve(InterventionCounter::class)->recompute();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleHealth(WebhookEvent $webhookEvent, array $payload, string $kind): void
    {
        $message = (string) ($payload['message'] ?? 'Unknown health event');

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
                'Radarr updated from %s to %s.',
                $previousVersion ?? 'unknown',
                $newVersion ?? 'unknown',
            ),
            metadata: [
                'previous_version' => $previousVersion,
                'new_version' => $newVersion,
                'message' => $payload['message'] ?? null,
            ],
        );
    }
}
