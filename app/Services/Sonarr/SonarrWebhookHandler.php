<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Cache\Services\SonarrCache;
use App\Enums\WebhookHandlingStatus;
use App\Jobs\AuditImportedSubtitles;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionDescriber;
use App\Services\Emby\EmbyLibraryScanScheduler;
use App\Services\Library\InterventionCounter;
use App\Services\MediaReplacement\MediaReplacementTracker;
use App\Services\Notifications\AdminNotifier;
use App\Services\Search\SeriesIndexer;
use App\Services\Webhook\AbstractArrWebhookHandler;

class SonarrWebhookHandler extends AbstractArrWebhookHandler
{
    public function __construct(
        private readonly SeriesIndexer $seriesIndexer,
        private readonly MediaReplacementTracker $mediaReplacementTracker,
        private readonly AdminNotifier $adminNotifier,
        private readonly ActionDescriber $actionDescriber,
        private readonly EmbyLibraryScanScheduler $embyLibraryScanScheduler,
    ) {}

    protected function serviceSlug(): string
    {
        return 'sonarr';
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
            'SeriesAdd' => $this->handleSeriesAdd($webhookEvent, $payload),
            'SeriesDelete' => $this->handleSeriesDelete($webhookEvent, $payload),
            'EpisodeFileDelete' => $this->handleEpisodeFileDelete($webhookEvent, $payload),
            'ManualInteractionRequired' => $this->handleManualInteractionRequired($webhookEvent, $payload),
            'Health' => $this->handleHealth($webhookEvent, $payload, 'health'),
            'HealthRestored' => $this->handleHealth($webhookEvent, $payload, 'health_restored'),
            'ApplicationUpdate' => $this->handleApplicationUpdate($webhookEvent, $payload),
            default => $status = $this->ignore($webhookEvent, $eventType),
        };

        if ($webhookEvent->serviceConnection !== null) {
            new SonarrCache($webhookEvent->serviceConnection)->bustAll();
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleGrab(WebhookEvent $webhookEvent, array $payload): void
    {
        $seriesTitle = $payload['series']['title'] ?? 'Unknown series';
        $episodes = $payload['episodes'] ?? [];
        $episodeCount = is_array($episodes) ? count($episodes) : 0;

        $this->logActivity(
            $webhookEvent,
            'grab',
            sprintf('Sonarr grabbed %d episode(s) for "%s".', $episodeCount, $seriesTitle),
            metadata: [
                'series_id' => $payload['series']['id'] ?? null,
                'tvdb_id' => $payload['series']['tvdbId'] ?? null,
                'episodes' => $episodes,
                'release' => $payload['release'] ?? null,
            ],
            subjectId: $payload['series']['id'] ?? null,
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
        $seriesTitle = $payload['series']['title'] ?? 'Unknown series';
        $episodes = $payload['episodes'] ?? [];
        $episodeCount = is_array($episodes) ? count($episodes) : 0;

        $this->logActivity(
            $webhookEvent,
            'download',
            sprintf('Sonarr imported %d episode(s) for "%s".', $episodeCount, $seriesTitle),
            metadata: [
                'series_id' => $payload['series']['id'] ?? null,
                'tvdb_id' => $payload['series']['tvdbId'] ?? null,
                'episodes' => $episodes,
                'episode_file' => $payload['episodeFile'] ?? null,
                'is_upgrade' => $payload['isUpgrade'] ?? null,
            ],
            subjectId: $payload['series']['id'] ?? null,
        );

        if ($webhookEvent->serviceConnection !== null) {
            $this->mediaReplacementTracker->verifyDownload($webhookEvent->serviceConnection, $payload);

            // Queued, not inline: the audit sweeps every indexer when a language
            // is missing, and its delay lets Sonarr finish the mediainfo scan
            // this import's subtitle list is read from.
            AuditImportedSubtitles::queueFor($webhookEvent);
        }

        $scanPayload = ['trigger' => 'sonarr_download', 'series_title' => $payload['series']['title'] ?? null];

        $this->embyLibraryScanScheduler->schedule(
            sourceService: 'sonarr',
            scanPayload: $scanPayload,
            description: $this->actionDescriber->describe('emby_library_scan', $scanPayload)
                ->because(sprintf('Sonarr imported "%s".', $seriesTitle))
                ->withDetail('Triggered by', sprintf('Sonarr › %s', $webhookEvent->serviceConnection?->name ?? 'unknown connection')),
            webhookEvent: $webhookEvent,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleRename(WebhookEvent $webhookEvent, array $payload): void
    {
        $seriesTitle = $payload['series']['title'] ?? 'Unknown series';

        $this->logActivity(
            $webhookEvent,
            'rename',
            sprintf('Sonarr renamed files for "%s".', $seriesTitle),
            metadata: [
                'series_id' => $payload['series']['id'] ?? null,
                'tvdb_id' => $payload['series']['tvdbId'] ?? null,
                'renamed_episode_files' => $payload['renamedEpisodeFiles'] ?? null,
            ],
            subjectId: $payload['series']['id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleSeriesAdd(WebhookEvent $webhookEvent, array $payload): void
    {
        $seriesTitle = $payload['series']['title'] ?? 'Unknown series';

        $this->logActivity(
            $webhookEvent,
            'series_added',
            sprintf('Sonarr added series "%s".', $seriesTitle),
            metadata: [
                'series_id' => $payload['series']['id'] ?? null,
                'tvdb_id' => $payload['series']['tvdbId'] ?? null,
                'path' => $payload['series']['path'] ?? null,
            ],
            subjectId: $payload['series']['id'] ?? null,
        );

        $series = $payload['series'] ?? null;

        if (is_array($series) && $webhookEvent->serviceConnection !== null) {
            $this->seriesIndexer->upsert($series, $webhookEvent->serviceConnection);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleSeriesDelete(WebhookEvent $webhookEvent, array $payload): void
    {
        $seriesTitle = $payload['series']['title'] ?? 'Unknown series';

        $this->logActivity(
            $webhookEvent,
            'series_deleted',
            sprintf('Sonarr deleted series "%s".', $seriesTitle),
            metadata: [
                'series_id' => $payload['series']['id'] ?? null,
                'tvdb_id' => $payload['series']['tvdbId'] ?? null,
                'deleted_files' => $payload['deletedFiles'] ?? null,
            ],
            subjectId: $payload['series']['id'] ?? null,
        );

        $sonarrId = (int) ($payload['series']['id'] ?? 0);

        if ($sonarrId > 0 && $webhookEvent->serviceConnection !== null) {
            $this->seriesIndexer->forget($sonarrId, $webhookEvent->serviceConnection);
        }

        $scanPayload = ['trigger' => 'sonarr_series_deleted', 'series_title' => $seriesTitle];

        $this->embyLibraryScanScheduler->schedule(
            sourceService: 'sonarr',
            scanPayload: $scanPayload,
            description: $this->actionDescriber->describe('emby_library_scan', $scanPayload)
                ->because(sprintf('Sonarr deleted "%s".', $seriesTitle))
                ->withDetail('Triggered by', sprintf('Sonarr › %s', $webhookEvent->serviceConnection?->name ?? 'unknown connection')),
            webhookEvent: $webhookEvent,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleEpisodeFileDelete(WebhookEvent $webhookEvent, array $payload): void
    {
        $seriesTitle = $payload['series']['title'] ?? 'Unknown series';

        $this->logActivity(
            $webhookEvent,
            'episode_file_deleted',
            sprintf('Sonarr deleted an episode file for "%s".', $seriesTitle),
            metadata: [
                'series_id' => $payload['series']['id'] ?? null,
                'tvdb_id' => $payload['series']['tvdbId'] ?? null,
                'episodes' => $payload['episodes'] ?? null,
                'episode_file' => $payload['episodeFile'] ?? null,
                'delete_reason' => $payload['deleteReason'] ?? null,
            ],
            subjectId: $payload['series']['id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleManualInteractionRequired(WebhookEvent $webhookEvent, array $payload): void
    {
        $seriesTitle = $payload['series']['title'] ?? 'Unknown series';
        $episodes = is_array($payload['episodes'] ?? null) ? $payload['episodes'] : [];
        $download = is_array($payload['downloadInfo'] ?? null) ? $payload['downloadInfo'] : [];
        $messages = is_array($payload['downloadStatusMessages'] ?? null) ? $payload['downloadStatusMessages'] : [];

        $this->logActivity(
            $webhookEvent,
            'manual_interaction_required',
            sprintf('Sonarr needs manual import for "%s" (%d episode(s)).', $seriesTitle, count($episodes)),
            metadata: [
                'series_id' => $payload['series']['id'] ?? null,
                'tvdb_id' => $payload['series']['tvdbId'] ?? null,
                'episodes' => $episodes,
                'download_id' => $payload['downloadId'] ?? ($download['downloadId'] ?? null),
                'download_client' => $payload['downloadClient'] ?? null,
                'download_title' => $download['title'] ?? null,
                'release_size' => $download['size'] ?? null,
                'status_messages' => $messages,
            ],
            subjectId: $payload['series']['id'] ?? null,
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
