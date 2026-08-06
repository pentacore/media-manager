<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Jobs\AuditImportedSubtitles;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Radarr\RadarrWebhookHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->radarr()->create();
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);
});

test('Grab event correlates a pending replacement attempt and attaches the download id', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $this->connection->id,
        'status' => MediaReplacementStatus::Requested,
        'scope' => 'movie',
        'target' => ['service' => 'radarr', 'scope' => 'movie', 'movie_id' => 88, 'movie_file_ids' => [601]],
        'candidate' => ['title' => 'A.Movie.2026.BluRay'],
        'download_id' => null,
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Grab',
        'payload' => [
            'eventType' => 'Grab',
            'movie' => ['id' => 88, 'title' => 'A Movie'],
            'release' => ['releaseTitle' => 'A.Movie.2026.BluRay'],
            'downloadId' => 'RDL-88',
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect($attempt->fresh()->download_id)->toBe('RDL-88');
});

test('Test event writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Test',
        'payload' => [
            'eventType' => 'Test',
            'instanceName' => 'Radarr',
            'applicationUrl' => 'http://radarr.local',
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.radarr.test',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('Grab event writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Grab',
        'payload' => [
            'eventType' => 'Grab',
            'movie' => ['id' => 10, 'title' => 'Inception', 'tmdbId' => 27205],
            'release' => ['quality' => '1080p'],
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $log = ActivityLog::where('action', 'webhook.radarr.grab')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Inception');
    expect($log->subject_id)->toBe(10);
});

test('Download event writes ActivityLog and dispatches emby_library_scan', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'movie' => ['id' => 10, 'title' => 'Inception', 'tmdbId' => 27205],
            'movieFile' => ['path' => '/movies/Inception.mkv'],
            'isUpgrade' => false,
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    $request = ActionRequest::first();
    expect($request)->not->toBeNull();
    expect($request->type)->toBe('emby_library_scan');
    expect($request->source_service)->toBe('radarr');
    expect($request->target_service)->toBe('emby');
    expect($request->webhook_event_id)->toBe($webhookEvent->id);
    expect($request->payload['trigger'] ?? null)->toBe('radarr_download');

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.radarr.download',
    ]);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('Rename event writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Rename',
        'payload' => [
            'eventType' => 'Rename',
            'movie' => ['id' => 10, 'title' => 'Inception'],
            'renamedMovieFiles' => [['previousPath' => '/old', 'path' => '/new']],
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.radarr.rename',
    ]);
});

test('MovieAdded writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MovieAdded',
        'payload' => [
            'eventType' => 'MovieAdded',
            'movie' => ['id' => 10, 'title' => 'Inception', 'tmdbId' => 27205, 'folderPath' => '/movies/Inception'],
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $log = ActivityLog::where('action', 'webhook.radarr.movie_added')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Inception');
});

test('MovieDelete writes ActivityLog and dispatches emby_library_scan', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MovieDelete',
        'payload' => [
            'eventType' => 'MovieDelete',
            'movie' => ['id' => 10, 'title' => 'Inception', 'tmdbId' => 27205],
            'deletedFiles' => true,
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    $request = ActionRequest::first();
    expect($request)->not->toBeNull();
    expect($request->type)->toBe('emby_library_scan');
    expect($request->payload['trigger'] ?? null)->toBe('radarr_movie_deleted');

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.radarr.movie_deleted',
    ]);
});

test('MovieFileDelete writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MovieFileDelete',
        'payload' => [
            'eventType' => 'MovieFileDelete',
            'movie' => ['id' => 10, 'title' => 'Inception'],
            'movieFile' => ['path' => '/movies/Inception.mkv'],
            'deleteReason' => 'Upgrade',
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.radarr.movie_file_deleted',
    ]);
});

test('ManualInteractionRequired event writes ActivityLog and triggers intervention recompute', function (): void {
    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []]),
    ]);
    $this->connection->update(['url' => 'http://radarr.fake:7878', 'is_active' => true]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'ManualInteractionRequired',
        'payload' => [
            'eventType' => 'ManualInteractionRequired',
            'movie' => ['id' => 77, 'title' => 'Stuck Movie', 'tmdbId' => 9999, 'imdbId' => 'tt000'],
            'downloadId' => 'NZB_XYZ',
            'downloadClient' => 'sabnzbd',
            'downloadInfo' => [
                'title' => 'Stuck.Movie.2026.mkv',
                'size' => 5_000_000,
            ],
            'downloadStatusMessages' => [
                ['title' => 'Stuck.Movie.2026.mkv', 'messages' => ['Sample folder is not allowed']],
            ],
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    $log = ActivityLog::where('action', 'webhook.radarr.manual_interaction_required')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Stuck Movie');
    expect($log->subject_id)->toBe(77);
    expect($log->metadata['download_id'])->toBe('NZB_XYZ');
});

test('Health event writes ActivityLog with level/type/wikiUrl metadata', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Health',
        'payload' => [
            'eventType' => 'Health',
            'level' => 'error',
            'message' => 'Download client unavailable',
            'type' => 'DownloadClientStatusCheck',
            'wikiUrl' => 'https://wiki.servarr.com/radarr/system#download-client-status',
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $log = ActivityLog::where('action', 'webhook.radarr.health')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toBe('Download client unavailable');
    expect($log->metadata['level'] ?? null)->toBe('error');
    expect($log->metadata['type'] ?? null)->toBe('DownloadClientStatusCheck');
    expect($log->metadata['wiki_url'] ?? null)->toBe('https://wiki.servarr.com/radarr/system#download-client-status');
});

test('HealthRestored writes ActivityLog', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'HealthRestored',
        'payload' => [
            'eventType' => 'HealthRestored',
            'level' => 'error',
            'message' => 'Download client restored',
            'type' => 'DownloadClientStatusCheck',
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.radarr.health_restored',
    ]);
});

test('ApplicationUpdate writes ActivityLog with version metadata', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'ApplicationUpdate',
        'payload' => [
            'eventType' => 'ApplicationUpdate',
            'previousVersion' => '5.0.0',
            'newVersion' => '5.0.1',
            'message' => 'Updated',
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    $log = ActivityLog::where('action', 'webhook.radarr.updated')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['previous_version'] ?? null)->toBe('5.0.0');
    expect($log->metadata['new_version'] ?? null)->toBe('5.0.1');
});

test('Unknown eventType is logged and skipped (no ActivityLog)', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'WeirdUnknown',
        'payload' => ['eventType' => 'WeirdUnknown'],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    expect(ActivityLog::count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('Download event queues the automatic subtitle check', function (): void {
    Queue::fake();

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'movie' => ['id' => 88, 'title' => 'A Movie'],
            'downloadId' => 'RDL-1',
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    Queue::assertPushed(AuditImportedSubtitles::class, fn (AuditImportedSubtitles $job): bool => $job->webhookEventId === $webhookEvent->id);
});

test('a non-import event does not queue the automatic subtitle check', function (): void {
    // Pins the dispatch to the Download branch: queueing it from handle() for
    // every event type would still satisfy the test above.
    Queue::fake();

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Rename',
        'payload' => [
            'eventType' => 'Rename',
            'movie' => ['id' => 88, 'title' => 'A Movie'],
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    Queue::assertNotPushed(AuditImportedSubtitles::class);
});

test('ProcessWebhookEvent routes Radarr connections to RadarrWebhookHandler', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'Download',
        'payload' => [
            'eventType' => 'Download',
            'movie' => ['id' => 10, 'title' => 'Inception'],
        ],
    ]);

    new ProcessWebhookEvent($webhookEvent)->handle();

    expect(ActionRequest::count())->toBe(1);
    expect(ActionRequest::first()->type)->toBe('emby_library_scan');
    expect(ActionRequest::first()->source_service)->toBe('radarr');

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.radarr.download',
    ]);
});
