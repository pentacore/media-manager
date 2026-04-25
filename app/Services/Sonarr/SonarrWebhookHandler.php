<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Models\WebhookEvent;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Webhook\AbstractWebhookHandler;
use Illuminate\Support\Facades\Log;

class SonarrWebhookHandler extends AbstractWebhookHandler
{
    public function __construct(private readonly ActionOrchestrator $actionOrchestrator) {}

    protected function serviceSlug(): string
    {
        return 'sonarr';
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
            'SeriesAdd' => $this->handleSeriesAdd($webhookEvent, $payload),
            'SeriesDelete' => $this->handleSeriesDelete($webhookEvent, $payload),
            'EpisodeFileDelete' => $this->handleEpisodeFileDelete($webhookEvent, $payload),
            'Health' => $this->handleHealth($webhookEvent, $payload, 'health'),
            'HealthRestored' => $this->handleHealth($webhookEvent, $payload, 'health_restored'),
            'ApplicationUpdate' => $this->handleApplicationUpdate($webhookEvent, $payload),
            default => Log::info('SonarrWebhookHandler: ignoring event', [
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
            'Sonarr webhook test received.',
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

        $this->actionOrchestrator->dispatch(
            type: 'emby_library_scan',
            sourceService: 'sonarr',
            targetService: 'emby',
            payload: [
                'trigger' => 'sonarr_download',
                'series_title' => $payload['series']['title'] ?? null,
            ],
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

        $this->actionOrchestrator->dispatch(
            type: 'emby_library_scan',
            sourceService: 'sonarr',
            targetService: 'emby',
            payload: [
                'trigger' => 'sonarr_series_deleted',
                'series_title' => $seriesTitle,
            ],
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
                'Sonarr updated from %s to %s.',
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
