<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Emby\EmbyWebhookHandler;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->emby()->create();
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);
    ActionTypeConfig::factory()->create(['type' => 'delete_movie', 'requires_approval' => true, 'is_enabled' => true]);
});

function makeEmbyWebhookEvent(ServiceConnection $serviceConnection, array $payload): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $serviceConnection->id,
        'event_type' => $payload['Event'] ?? 'unknown',
        'payload' => $payload,
    ]);
}

test('library.deleted for Series with SonarrSeriesId dispatches delete_series', function (): void {
    $webhookEvent = makeEmbyWebhookEvent($this->connection, [
        'Event' => 'library.deleted',
        'Item' => [
            'Id' => 'emby-1',
            'Type' => 'Series',
            'Name' => 'My Show',
            'ProviderIds' => ['SonarrSeriesId' => '42', 'Tvdb' => '12345'],
        ],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    $request = ActionRequest::first();
    expect($request)->not->toBeNull();
    expect($request->type)->toBe('delete_series');
    expect($request->source_service)->toBe('emby');
    expect($request->target_service)->toBe('sonarr');
    expect($request->payload)->toMatchArray(['sonarr_series_id' => 42, 'delete_files' => true]);
    expect($request->webhook_event_id)->toBe($webhookEvent->id);
});

test('library.deleted for Movie with RadarrMovieId dispatches delete_movie', function (): void {
    $webhookEvent = makeEmbyWebhookEvent($this->connection, [
        'Event' => 'library.deleted',
        'Item' => [
            'Id' => 'emby-2',
            'Type' => 'Movie',
            'Name' => 'My Movie',
            'ProviderIds' => ['RadarrMovieId' => '99', 'Tmdb' => '67890'],
        ],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    $request = ActionRequest::first();
    expect($request->type)->toBe('delete_movie');
    expect($request->payload)->toMatchArray(['radarr_movie_id' => 99, 'delete_files' => true]);
});

test('library.deleted without ids is skipped', function (): void {
    $webhookEvent = makeEmbyWebhookEvent($this->connection, [
        'Event' => 'library.deleted',
        'Item' => ['Id' => 'x', 'Type' => 'Series', 'Name' => 'X', 'ProviderIds' => []],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('library.deleted for unsupported type is skipped', function (): void {
    $webhookEvent = makeEmbyWebhookEvent($this->connection, [
        'Event' => 'library.deleted',
        'Item' => ['Id' => 'x', 'Type' => 'Season', 'Name' => 'X', 'ProviderIds' => ['SonarrSeriesId' => '1']],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    expect(ActionRequest::count())->toBe(0);
});

test('playback events still work (smoke test for regression)', function (): void {
    EmbyUserLink::factory()->create(['emby_user_id' => 'emby-u-1']);

    $webhookEvent = makeEmbyWebhookEvent($this->connection, [
        'Event' => 'playback.start',
        'User' => ['Id' => 'emby-u-1'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Movie', 'Name' => 'M', 'RunTimeTicks' => 1],
        'PlaybackInfo' => ['PositionTicks' => 0, 'PlayedToCompletion' => false],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    expect(EmbyActivity::count())->toBe(1);
});
