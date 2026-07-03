<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\ServiceWarning;
use App\Services\Whisparr\WhisparrWebhookHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->whisparr()->create();
});

function whisparrEvent(int $connectionId, string $type, array $payload): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $connectionId,
        'event_type' => $type,
        'payload' => ['eventType' => $type, ...$payload],
    ]);
}

test('Test event writes ActivityLog and never dispatches an ActionRequest', function (): void {
    $webhookEvent = whisparrEvent($this->connection->id, 'Test', [
        'instanceName' => 'Whisparr',
        'applicationUrl' => 'http://whisparr.local',
    ]);

    resolve(WhisparrWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('activity_logs', [
        'service_connection_id' => $this->connection->id,
        'action' => 'webhook.whisparr.test',
    ]);
    expect(ActionRequest::count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('v3 movie events write ActivityLog and never dispatch emby_library_scan', function (string $type, string $action): void {
    $webhookEvent = whisparrEvent($this->connection->id, $type, [
        'movie' => ['id' => 10, 'title' => 'Some Title', 'tmdbId' => 27205],
        'deletedFiles' => true,
        'movieFile' => ['path' => '/x.mkv'],
    ]);

    resolve(WhisparrWebhookHandler::class)->handle($webhookEvent);

    $log = ActivityLog::where('action', $action)->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Some Title');
    expect(ActionRequest::count())->toBe(0);
})->with([
    ['Grab', 'webhook.whisparr.grab'],
    ['Download', 'webhook.whisparr.download'],
    ['Rename', 'webhook.whisparr.rename'],
    ['MovieAdded', 'webhook.whisparr.movie_added'],
    ['MovieDelete', 'webhook.whisparr.movie_deleted'],
    ['MovieFileDelete', 'webhook.whisparr.movie_file_deleted'],
]);

test('v2 series events write ActivityLog and never dispatch emby_library_scan', function (string $type, string $action): void {
    $webhookEvent = whisparrEvent($this->connection->id, $type, [
        'series' => ['id' => 5, 'title' => 'A Series', 'tvdbId' => 999],
        'deletedFiles' => true,
        'episodes' => [['id' => 1]],
        'episodeFile' => ['path' => '/x.mkv'],
    ]);

    resolve(WhisparrWebhookHandler::class)->handle($webhookEvent);

    $log = ActivityLog::where('action', $action)->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('A Series');
    expect(ActionRequest::count())->toBe(0);
})->with([
    ['SeriesAdd', 'webhook.whisparr.series_added'],
    ['SeriesDelete', 'webhook.whisparr.series_deleted'],
    ['EpisodeFileDelete', 'webhook.whisparr.episode_file_deleted'],
]);

test('ManualInteractionRequired recomputes intervention counter', function (): void {
    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []])]);
    $this->connection->update(['url' => 'http://whisparr.fake:6969', 'is_active' => true]);

    $webhookEvent = whisparrEvent($this->connection->id, 'ManualInteractionRequired', [
        'movie' => ['id' => 77, 'title' => 'Stuck'],
        'downloadId' => 'NZB_X',
    ]);

    resolve(WhisparrWebhookHandler::class)->handle($webhookEvent);

    $log = ActivityLog::where('action', 'webhook.whisparr.manual_interaction_required')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['download_id'] ?? null)->toBe('NZB_X');
});

test('Health warning notifies admins; HealthRestored does not', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    resolve(WhisparrWebhookHandler::class)->handle(
        whisparrEvent($this->connection->id, 'Health', ['level' => 'error', 'message' => 'Bad', 'type' => 'Check']),
    );
    Notification::assertSentTo($admin, ServiceWarning::class);

    Notification::fake();
    resolve(WhisparrWebhookHandler::class)->handle(
        whisparrEvent($this->connection->id, 'HealthRestored', ['level' => 'error', 'message' => 'OK']),
    );
    Notification::assertNothingSent();
});

test('Unknown eventType is skipped (no ActivityLog) but still marked processed', function (): void {
    $webhookEvent = whisparrEvent($this->connection->id, 'WeirdUnknown', []);

    resolve(WhisparrWebhookHandler::class)->handle($webhookEvent);

    expect(ActivityLog::count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});
