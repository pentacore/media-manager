<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Events\WebhookReceived;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

#[Signature('demo:fake-webhooks
    {--service= : Limit to one service (sonarr|radarr|emby|seerr)}
    {--delay=2 : Seconds between webhooks so the UI has time to render}')]
#[Description('Fire a curated set of fake webhooks across services to demo realtime UI.')]
class DemoFakeWebhooks extends Command
{
    private bool $reverbWarned = false;

    public function handle(): int
    {
        $only = $this->option('service');
        $delaySeconds = max(0, (int) $this->option('delay'));

        $scenarios = collect($this->scenarios())
            ->when($only, fn ($collection) => $collection->where('service', $only));

        if ($scenarios->isEmpty()) {
            $this->error('No matching scenarios — pass --service=sonarr|radarr|emby|seerr or omit to run all.');

            return self::FAILURE;
        }

        foreach ($scenarios as $scenario) {
            $connection = ServiceConnection::where('type', $scenario['service'])
                ->where('is_active', true)
                ->first();

            if ($connection === null) {
                $this->warn(sprintf('  [skip] no active %s connection', $scenario['service']));

                continue;
            }

            $this->dispatchScenario($connection, $scenario);

            if ($delaySeconds > 0 && $scenario !== $scenarios->last()) {
                Sleep::sleep($delaySeconds);
            }
        }

        $this->info('Done. Watch the Activity Log, Dashboard, and Now Playing pages.');

        return self::SUCCESS;
    }

    /**
     * @param  array{service: string, event_type: string, label: string, payload: array<string, mixed>}  $scenario
     */
    private function dispatchScenario(ServiceConnection $serviceConnection, array $scenario): void
    {
        // Inject a unique nonce so payload_hash differs from prior runs and the
        // dedupe in WebhookController doesn't swallow repeat demo invocations.
        $payload = $scenario['payload'];
        $payload['_demo_nonce'] = (string) Str::uuid();

        $webhookEvent = WebhookEvent::create([
            'service_connection_id' => $serviceConnection->id,
            'event_type' => $scenario['event_type'],
            'payload_hash' => WebhookEvent::payloadHash($payload),
            'payload' => $payload,
        ]);

        $webhookEvent->setRelation('serviceConnection', $serviceConnection);

        try {
            event(new WebhookReceived($webhookEvent));
            dispatch_sync(new ProcessWebhookEvent($webhookEvent));
        } catch (BroadcastException $e) {
            $this->warnReverbOffline($e);
        } catch (Throwable $e) {
            // Tolerate downstream errors so a single bad scenario doesn't abort
            // the demo run. Surface it on the line so the user can see.
            $this->line(sprintf('  [%s] %s — failed: %s', $scenario['service'], $scenario['label'], $e->getMessage()));

            return;
        }

        $this->line(sprintf('  [%s] %s', $scenario['service'], $scenario['label']));
    }

    private function warnReverbOffline(BroadcastException $broadcastException): void
    {
        if ($this->reverbWarned) {
            return;
        }

        $this->reverbWarned = true;
        $this->newLine();
        $this->warn('Broadcast failed — Reverb is not reachable.');
        $this->warn('Start it with `vendor/bin/sail composer run dev` (boots app, queue, reverb, vite).');
        $this->line(sprintf('  underlying error: %s', $broadcastException->getMessage()));
        $this->newLine();
    }

    /**
     * @return list<array{service: string, event_type: string, label: string, payload: array<string, mixed>}>
     */
    private function scenarios(): array
    {
        return [
            [
                'service' => ServiceType::Sonarr->value,
                'event_type' => 'Grab',
                'label' => 'Sonarr grabbed S01E04 of "Demo Show"',
                'payload' => [
                    'eventType' => 'Grab',
                    'instanceName' => 'Sonarr',
                    'series' => ['id' => 42, 'title' => 'Demo Show', 'tvdbId' => 999001],
                    'episodes' => [['episodeNumber' => 4, 'seasonNumber' => 1, 'title' => 'The Pilot']],
                    'release' => ['releaseTitle' => 'Demo.Show.S01E04.1080p', 'indexer' => 'DemoIndexer'],
                ],
            ],
            [
                'service' => ServiceType::Sonarr->value,
                'event_type' => 'Download',
                'label' => 'Sonarr imported S01E04 of "Demo Show" (triggers Emby scan)',
                'payload' => [
                    'eventType' => 'Download',
                    'series' => ['id' => 42, 'title' => 'Demo Show', 'tvdbId' => 999001],
                    'episodes' => [['episodeNumber' => 4, 'seasonNumber' => 1, 'title' => 'The Pilot']],
                    'episodeFile' => ['relativePath' => 'Demo Show/Season 01/Demo.Show.S01E04.mkv'],
                    'isUpgrade' => false,
                ],
            ],
            [
                'service' => ServiceType::Sonarr->value,
                'event_type' => 'SeriesAdd',
                'label' => 'Sonarr added new series "New Demo Series"',
                'payload' => [
                    'eventType' => 'SeriesAdd',
                    'series' => [
                        'id' => 77,
                        'title' => 'New Demo Series',
                        'tvdbId' => 999077,
                        'path' => '/tv/New Demo Series',
                    ],
                ],
            ],
            [
                'service' => ServiceType::Radarr->value,
                'event_type' => 'Grab',
                'label' => 'Radarr grabbed "Demo Movie (2026)"',
                'payload' => [
                    'eventType' => 'Grab',
                    'movie' => ['id' => 200, 'title' => 'Demo Movie', 'year' => 2026, 'tmdbId' => 999200],
                    'release' => ['releaseTitle' => 'Demo.Movie.2026.1080p', 'indexer' => 'DemoIndexer'],
                ],
            ],
            [
                'service' => ServiceType::Radarr->value,
                'event_type' => 'Download',
                'label' => 'Radarr imported "Demo Movie (2026)" (triggers Emby scan)',
                'payload' => [
                    'eventType' => 'Download',
                    'movie' => ['id' => 200, 'title' => 'Demo Movie', 'year' => 2026, 'tmdbId' => 999200],
                    'movieFile' => ['relativePath' => 'Demo Movie (2026)/Demo.Movie.2026.mkv'],
                    'isUpgrade' => false,
                ],
            ],
            [
                'service' => ServiceType::Seerr->value,
                'event_type' => 'MEDIA_PENDING',
                'label' => 'Seerr received a new request for "Pending Movie"',
                'payload' => [
                    'notification_type' => 'MEDIA_PENDING',
                    'subject' => 'Pending Movie (2026)',
                    'message' => 'A new request awaits approval.',
                    'media' => ['media_type' => 'movie', 'tmdbId' => 999301],
                    'request' => ['request_id' => 5101, 'requestedBy_username' => 'demo-user'],
                ],
            ],
            [
                'service' => ServiceType::Seerr->value,
                'event_type' => 'MEDIA_APPROVED',
                'label' => 'Seerr approved request for "Approved Series"',
                'payload' => [
                    'notification_type' => 'MEDIA_APPROVED',
                    'subject' => 'Approved Series (2026)',
                    'media' => ['media_type' => 'tv', 'tvdbId' => 999401],
                    'request' => ['request_id' => 5102, 'requestedBy_username' => 'demo-user'],
                ],
            ],
            [
                'service' => ServiceType::Seerr->value,
                'event_type' => 'MEDIA_AVAILABLE',
                'label' => 'Seerr reported media available for "Now Streaming Movie" (triggers Emby scan)',
                'payload' => [
                    'notification_type' => 'MEDIA_AVAILABLE',
                    'subject' => 'Now Streaming Movie (2026)',
                    'media' => ['media_type' => 'movie', 'tmdbId' => 999500],
                    'request' => ['request_id' => 5103, 'requestedBy_username' => 'demo-user'],
                ],
            ],
            [
                'service' => ServiceType::Emby->value,
                'event_type' => 'playback.start',
                'label' => 'Emby playback started for "Demo Episode" (only fires for linked Emby users)',
                'payload' => [
                    'Event' => 'playback.start',
                    'User' => ['Id' => 'demo-emby-user-id', 'Name' => 'demo-user'],
                    'Item' => [
                        'Id' => 'demo-item-1',
                        'Name' => 'Demo Episode S01E01',
                        'Type' => 'Episode',
                        'SeriesName' => 'Demo Show',
                        'RunTimeTicks' => 18_000_000_000,
                    ],
                    'PlaybackInfo' => ['PositionTicks' => 0, 'PlayedToCompletion' => false],
                ],
            ],
            [
                'service' => ServiceType::Emby->value,
                'event_type' => 'playback.stop',
                'label' => 'Emby playback finished for "Demo Movie"',
                'payload' => [
                    'Event' => 'playback.stop',
                    'User' => ['Id' => 'demo-emby-user-id', 'Name' => 'demo-user'],
                    'Item' => [
                        'Id' => 'demo-item-2',
                        'Name' => 'Demo Movie',
                        'Type' => 'Movie',
                        'RunTimeTicks' => 72_000_000_000,
                    ],
                    'PlaybackInfo' => ['PositionTicks' => 72_000_000_000, 'PlayedToCompletion' => true],
                ],
            ],
        ];
    }
}
