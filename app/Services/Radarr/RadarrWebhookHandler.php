<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Cache\Services\RadarrCache;
use App\Enums\WebhookHandlingStatus;
use App\Jobs\AuditImportedSubtitles;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionDescriber;
use App\Services\Emby\EmbyLibraryScanScheduler;
use App\Services\Library\InterventionCounter;
use App\Services\MediaReplacement\MediaReplacementTracker;
use App\Services\Notifications\AdminNotifier;
use App\Services\Search\MovieIndexer;
use App\Services\Webhook\AbstractArrWebhookHandler;

class RadarrWebhookHandler extends AbstractArrWebhookHandler
{
    public function __construct(
        private readonly MovieIndexer $movieIndexer,
        private readonly MediaReplacementTracker $mediaReplacementTracker,
        private readonly AdminNotifier $adminNotifier,
        private readonly ActionDescriber $actionDescriber,
        private readonly EmbyLibraryScanScheduler $embyLibraryScanScheduler,
    ) {}

    protected function serviceSlug(): string
    {
        return 'radarr';
    }

    protected function adminNotifier(): AdminNotifier
    {
        return $this->adminNotifier;
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
            'MovieAdded' => $this->handleMovieAdded($webhookEvent, $payload),
            'MovieDelete' => $this->handleMovieDelete($webhookEvent, $payload),
            'MovieFileDelete' => $this->handleMovieFileDelete($webhookEvent, $payload),
            'ManualInteractionRequired' => $this->handleManualInteractionRequired($webhookEvent, $payload),
            'Health' => $this->handleHealth($webhookEvent, $payload, 'health'),
            'HealthRestored' => $this->handleHealth($webhookEvent, $payload, 'health_restored'),
            'ApplicationUpdate' => $this->handleApplicationUpdate($webhookEvent, $payload),
            default => $status = $this->ignore($webhookEvent, $eventType),
        };

        if ($webhookEvent->serviceConnection !== null) {
            new RadarrCache($webhookEvent->serviceConnection)->bustAll();
        }

        return $status;
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

        if ($webhookEvent->serviceConnection !== null) {
            $this->mediaReplacementTracker->recordGrab($webhookEvent->serviceConnection, $payload);
        }
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

        if ($webhookEvent->serviceConnection !== null) {
            $this->mediaReplacementTracker->verifyDownload($webhookEvent->serviceConnection, $payload);

            // Queued, not inline: the audit sweeps every indexer when a language
            // is missing, and its delay lets Radarr finish the mediainfo scan
            // this import's subtitle list is read from.
            AuditImportedSubtitles::queueFor($webhookEvent);
        }

        $scanPayload = ['trigger' => 'radarr_download', 'movie_title' => $movieTitle];

        $this->embyLibraryScanScheduler->schedule(
            sourceService: 'radarr',
            scanPayload: $scanPayload,
            description: $this->actionDescriber->describe('emby_library_scan', $scanPayload)
                ->because(sprintf('Radarr imported "%s".', $movieTitle))
                ->withDetail('Triggered by', sprintf('Radarr › %s', $webhookEvent->serviceConnection?->name ?? 'unknown connection')),
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

        $movie = $payload['movie'] ?? null;

        if (is_array($movie) && $webhookEvent->serviceConnection !== null) {
            $this->movieIndexer->upsert($movie, $webhookEvent->serviceConnection);
        }
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

        $radarrId = (int) ($payload['movie']['id'] ?? 0);

        if ($radarrId > 0 && $webhookEvent->serviceConnection !== null) {
            $this->movieIndexer->forget($radarrId, $webhookEvent->serviceConnection);
        }

        $scanPayload = ['trigger' => 'radarr_movie_deleted', 'movie_title' => $movieTitle];

        $this->embyLibraryScanScheduler->schedule(
            sourceService: 'radarr',
            scanPayload: $scanPayload,
            description: $this->actionDescriber->describe('emby_library_scan', $scanPayload)
                ->because(sprintf('Radarr deleted "%s".', $movieTitle))
                ->withDetail('Triggered by', sprintf('Radarr › %s', $webhookEvent->serviceConnection?->name ?? 'unknown connection')),
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

        if ($webhookEvent->serviceConnection !== null) {
            $this->mediaReplacementTracker->recordManualIntervention($webhookEvent->serviceConnection, $payload);
        }

        // The library activity badge needs to reflect the new stuck import
        // immediately — without this it would only update on the next
        // scheduled poll (5 min) or page reload.
        resolve(InterventionCounter::class)->recompute();
    }
}
