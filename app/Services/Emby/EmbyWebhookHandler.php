<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Events\EmbyPlaybackUpdated;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Webhook\AbstractWebhookHandler;
use Illuminate\Support\Facades\Log;

class EmbyWebhookHandler extends AbstractWebhookHandler
{
    private const array TERMINAL_ACTIONS = ['stopped', 'finished'];

    public function __construct(private readonly ActionOrchestrator $actionOrchestrator) {}

    protected function serviceSlug(): string
    {
        return 'emby';
    }

    public function handle(WebhookEvent $webhookEvent): void
    {
        $payload = $webhookEvent->payload;

        if (($payload['Event'] ?? null) === 'library.deleted') {
            $this->handleLibraryDeleted($webhookEvent, $payload);
            $webhookEvent->markProcessed();

            return;
        }

        $embyEvent = $payload['Event'] ?? null;
        $action = $this->mapAction($embyEvent, $payload);

        if ($action === null) {
            Log::info('EmbyWebhookHandler: ignoring unsupported event', [
                'webhook_event_id' => $webhookEvent->id,
                'emby_event' => $embyEvent,
            ]);

            $webhookEvent->markProcessed();

            return;
        }

        $mediaType = $this->mapMediaType($payload['Item']['Type'] ?? null);
        if ($mediaType === null) {
            Log::info('EmbyWebhookHandler: ignoring unsupported media type', [
                'webhook_event_id' => $webhookEvent->id,
                'item_type' => $payload['Item']['Type'] ?? null,
            ]);

            $webhookEvent->markProcessed();

            return;
        }

        $embyUserId = $payload['User']['Id'] ?? null;
        if ($embyUserId === null) {
            Log::warning('EmbyWebhookHandler: payload missing User.Id', [
                'webhook_event_id' => $webhookEvent->id,
            ]);

            $webhookEvent->markProcessed();

            return;
        }

        $userLink = EmbyUserLink::where('emby_user_id', $embyUserId)->first();
        if ($userLink === null) {
            Log::info('EmbyWebhookHandler: no EmbyUserLink for emby user — skipping', [
                'webhook_event_id' => $webhookEvent->id,
                'emby_user_id' => $embyUserId,
            ]);

            $webhookEvent->markProcessed();

            return;
        }

        $embyItemId = (string) ($payload['Item']['Id'] ?? '');
        if ($embyItemId === '') {
            Log::warning('EmbyWebhookHandler: payload missing Item.Id', [
                'webhook_event_id' => $webhookEvent->id,
            ]);

            $webhookEvent->markProcessed();

            return;
        }

        $attributes = [
            'media_type' => $mediaType,
            'media_title' => $payload['Item']['Name'] ?? null,
            'series_title' => $payload['Item']['SeriesName'] ?? null,
            'action' => $action,
            'duration_ticks' => $payload['Item']['RunTimeTicks'] ?? null,
            'play_position' => $payload['PlaybackInfo']['PositionTicks'] ?? null,
        ];

        if (in_array($action, self::TERMINAL_ACTIONS, true)) {
            $activity = EmbyActivity::create([
                'emby_user_link_id' => $userLink->id,
                'emby_item_id' => $embyItemId,
                ...$attributes,
            ]);
        } else {
            $activity = EmbyActivity::updateOrCreate(
                [
                    'emby_user_link_id' => $userLink->id,
                    'emby_item_id' => $embyItemId,
                    'action' => 'played',
                ],
                $attributes,
            );
        }

        $activity->setRelation('embyUserLink', $userLink);
        event(new EmbyPlaybackUpdated($activity));

        $webhookEvent->markProcessed();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mapAction(?string $embyEvent, array $payload): ?string
    {
        return match ($embyEvent) {
            'playback.start', 'playback.unpause' => 'played',
            'playback.pause' => 'stopped',
            'playback.stop' => ($payload['PlaybackInfo']['PlayedToCompletion'] ?? false) ? 'finished' : 'stopped',
            'item.markplayed' => 'finished',
            default => null,
        };
    }

    private function mapMediaType(?string $itemType): ?string
    {
        return match ($itemType) {
            'Movie' => 'movie',
            'Episode' => 'episode',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleLibraryDeleted(WebhookEvent $webhookEvent, array $payload): void
    {
        $itemType = $payload['Item']['Type'] ?? null;
        $providerIds = $payload['Item']['ProviderIds'] ?? [];

        if ($itemType === 'Series') {
            $sonarrId = isset($providerIds['SonarrSeriesId']) ? (int) $providerIds['SonarrSeriesId'] : null;
            if ($sonarrId === null || $sonarrId <= 0) {
                Log::info('EmbyWebhookHandler: library.deleted for Series without SonarrSeriesId — skipping', [
                    'webhook_event_id' => $webhookEvent->id,
                ]);

                return;
            }

            $this->actionOrchestrator->dispatch(
                type: 'delete_series',
                sourceService: 'emby',
                targetService: 'sonarr',
                payload: ['sonarr_series_id' => $sonarrId, 'delete_files' => true],
                webhookEvent: $webhookEvent,
            );

            return;
        }

        if ($itemType === 'Movie') {
            $radarrId = isset($providerIds['RadarrMovieId']) ? (int) $providerIds['RadarrMovieId'] : null;
            if ($radarrId === null || $radarrId <= 0) {
                Log::info('EmbyWebhookHandler: library.deleted for Movie without RadarrMovieId — skipping', [
                    'webhook_event_id' => $webhookEvent->id,
                ]);

                return;
            }

            $this->actionOrchestrator->dispatch(
                type: 'delete_movie',
                sourceService: 'emby',
                targetService: 'radarr',
                payload: ['radarr_movie_id' => $radarrId, 'delete_files' => true],
                webhookEvent: $webhookEvent,
            );

            return;
        }

        Log::info('EmbyWebhookHandler: library.deleted for unsupported item type — skipping', [
            'webhook_event_id' => $webhookEvent->id,
            'item_type' => $itemType,
        ]);
    }
}
