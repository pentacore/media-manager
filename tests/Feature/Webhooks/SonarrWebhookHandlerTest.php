<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Sonarr\SonarrWebhookHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->sonarr()->create();
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);
});

test('Download event dispatches emby_library_scan', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'series' => ['id' => 42, 'title' => 'My Show'],
            'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 1]],
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    $request = ActionRequest::first();
    expect($request)->not->toBeNull();
    expect($request->type)->toBe('emby_library_scan');
    expect($request->source_service)->toBe('sonarr');
    expect($request->target_service)->toBe('emby');
    expect($request->webhook_event_id)->toBe($webhookEvent->id);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.sonarr.download',
    ]);
});

test('Grab event correlates a pending replacement attempt and attaches the download id', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $this->connection->id,
        'status' => MediaReplacementStatus::Requested,
        'target' => ['service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42, 'episode_file_ids' => [501]],
        'candidate' => ['title' => 'My.Show.S01E01.CR'],
        'download_id' => null,
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Grab',
        'payload' => [
            'eventType' => 'Grab',
            'series' => ['id' => 42, 'title' => 'My Show'],
            'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 1]],
            'release' => ['releaseTitle' => 'My.Show.S01E01.CR'],
            'downloadId' => 'DL-42',
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect($attempt->fresh()->download_id)->toBe('DL-42');
});

test('Download event verifies a tracked replacement attempt', function (): void {
    Cache::flush();
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'My Show', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'My.Show.S01E01.CR', 'mediaInfo' => ['subtitles' => 'English'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    // The inspector resolves the active Sonarr connection itself, so point the
    // single connection at the faked host.
    $this->connection->update(['url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true]);
    $connection = $this->connection;

    $attempt = MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $connection->id,
        'status' => MediaReplacementStatus::Downloading,
        'target' => ['service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42, 'season_number' => 1, 'episode_numbers' => [1], 'episode_file_ids' => [501]],
        'required_languages' => ['eng'],
        'download_id' => 'DL-99',
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'series' => ['id' => 42, 'title' => 'My Show'],
            'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 1]],
            'downloadId' => 'DL-99',
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::Verified);
});

test('unknown events are ignored', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Mystery',
        'payload' => ['eventType' => 'Mystery'],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    expect(ActivityLog::count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('Test event writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Test',
        'payload' => [
            'eventType' => 'Test',
            'instanceName' => 'Sonarr',
            'applicationUrl' => 'http://sonarr.local',
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.sonarr.test',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('Grab event writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Grab',
        'payload' => [
            'eventType' => 'Grab',
            'series' => ['id' => 42, 'title' => 'My Show', 'tvdbId' => 1234],
            'episodes' => [
                ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'],
                ['seasonNumber' => 1, 'episodeNumber' => 2, 'title' => 'Second'],
            ],
            'release' => ['quality' => '1080p'],
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $log = ActivityLog::where('action', 'webhook.sonarr.grab')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('My Show');
    expect($log->subject_id)->toBe(42);
});

test('Rename event writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Rename',
        'payload' => [
            'eventType' => 'Rename',
            'series' => ['id' => 42, 'title' => 'My Show'],
            'renamedEpisodeFiles' => [['previousPath' => '/old', 'path' => '/new']],
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.sonarr.rename',
    ]);
});

test('SeriesAdd writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'SeriesAdd',
        'payload' => [
            'eventType' => 'SeriesAdd',
            'series' => ['id' => 42, 'title' => 'New Show', 'tvdbId' => 1234, 'path' => '/tv/New Show'],
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $log = ActivityLog::where('action', 'webhook.sonarr.series_added')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('New Show');
});

test('SeriesDelete writes ActivityLog and dispatches emby_library_scan', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'SeriesDelete',
        'payload' => [
            'eventType' => 'SeriesDelete',
            'series' => ['id' => 42, 'title' => 'Dead Show', 'tvdbId' => 1234],
            'deletedFiles' => true,
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    $request = ActionRequest::first();
    expect($request)->not->toBeNull();
    expect($request->type)->toBe('emby_library_scan');
    expect($request->payload['trigger'] ?? null)->toBe('sonarr_series_deleted');

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.sonarr.series_deleted',
    ]);
});

test('EpisodeFileDelete writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'EpisodeFileDelete',
        'payload' => [
            'eventType' => 'EpisodeFileDelete',
            'series' => ['id' => 42, 'title' => 'My Show'],
            'episodes' => [['seasonNumber' => 1, 'episodeNumber' => 1]],
            'episodeFile' => ['path' => '/tv/show/s01e01.mkv'],
            'deleteReason' => 'Upgrade',
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.sonarr.episode_file_deleted',
    ]);
});

test('ManualInteractionRequired event writes ActivityLog and triggers intervention recompute', function (): void {
    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []]),
    ]);
    $this->connection->update(['url' => 'http://sonarr.fake:8989', 'is_active' => true]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'ManualInteractionRequired',
        'payload' => [
            'eventType' => 'ManualInteractionRequired',
            'series' => ['id' => 88, 'title' => 'Stuck Show', 'tvdbId' => 9999],
            'episodes' => [
                ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'],
            ],
            'downloadId' => 'TORRENT_ABC',
            'downloadClient' => 'qbittorrent',
            'downloadInfo' => [
                'title' => 'Stuck.Show.S01E01.mkv',
                'size' => 1_000_000,
            ],
            'downloadStatusMessages' => [
                ['title' => 'Stuck.Show.S01E01.mkv', 'messages' => ['Sample folder is not allowed']],
            ],
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    $log = ActivityLog::where('action', 'webhook.sonarr.manual_interaction_required')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Stuck Show');
    expect($log->subject_id)->toBe(88);
    expect($log->metadata['download_id'])->toBe('TORRENT_ABC');
    expect($log->metadata['status_messages'][0]['messages'][0])->toBe('Sample folder is not allowed');
});

test('Health event writes ActivityLog with level/type/wikiUrl metadata', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Health',
        'payload' => [
            'eventType' => 'Health',
            'level' => 'warning',
            'message' => 'Indexer unavailable',
            'type' => 'IndexerStatusCheck',
            'wikiUrl' => 'https://wiki.servarr.com/sonarr/system#indexer-status',
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $log = ActivityLog::where('action', 'webhook.sonarr.health')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toBe('Indexer unavailable');
    expect($log->metadata['level'] ?? null)->toBe('warning');
    expect($log->metadata['type'] ?? null)->toBe('IndexerStatusCheck');
    expect($log->metadata['wiki_url'] ?? null)->toBe('https://wiki.servarr.com/sonarr/system#indexer-status');
});

test('HealthRestored writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'HealthRestored',
        'payload' => [
            'eventType' => 'HealthRestored',
            'level' => 'warning',
            'message' => 'Indexer restored',
            'type' => 'IndexerStatusCheck',
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.sonarr.health_restored',
    ]);
});

test('ApplicationUpdate writes ActivityLog with version metadata', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'ApplicationUpdate',
        'payload' => [
            'eventType' => 'ApplicationUpdate',
            'previousVersion' => '4.0.0',
            'newVersion' => '4.0.1',
            'message' => 'Updated',
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $log = ActivityLog::where('action', 'webhook.sonarr.updated')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['previous_version'] ?? null)->toBe('4.0.0');
    expect($log->metadata['new_version'] ?? null)->toBe('4.0.1');
});

test('Unknown eventType is logged and skipped (no ActivityLog)', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'WeirdUnknown',
        'payload' => ['eventType' => 'WeirdUnknown'],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    expect(ActivityLog::count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('ProcessWebhookEvent routes sonarr connections to SonarrWebhookHandler', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Download',
        'payload' => ['eventType' => 'Download', 'series' => ['id' => 42, 'title' => 'X']],
    ]);

    new ProcessWebhookEvent($webhookEvent)->handle();

    expect(ActionRequest::count())->toBe(1);
    expect(ActionRequest::first()->type)->toBe('emby_library_scan');
});
