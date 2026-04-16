<?php

declare(strict_types=1);

use App\Events\EmbyPlaybackUpdated;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Emby\EmbyWebhookHandler;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([EmbyPlaybackUpdated::class]);
    $this->connection = ServiceConnection::factory()->emby()->create();
    $this->userLink = EmbyUserLink::factory()->create(['emby_user_id' => 'emby-user-1']);
});

/**
 * @param array<string, mixed> $payload
 */
function makeWebhookEvent(ServiceConnection $serviceConnection, array $payload): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'service_connection_id' => $serviceConnection->id,
        'event_type' => $payload['Event'] ?? 'unknown',
        'payload' => $payload,
    ]);
}

test('playback.start creates an in-progress activity row', function (): void {
    $webhookEvent = makeWebhookEvent($this->connection, [
        'Event' => 'playback.start',
        'User' => ['Id' => 'emby-user-1', 'Name' => 'alice'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Episode', 'Name' => 'Pilot', 'SeriesName' => 'My Show', 'RunTimeTicks' => 12000000000],
        'PlaybackInfo' => ['PositionTicks' => 0, 'PlayedToCompletion' => false],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('emby_activities', [
        'emby_user_link_id' => $this->userLink->id,
        'emby_item_id' => 'item-1',
        'action' => 'played',
        'media_type' => 'episode',
        'media_title' => 'Pilot',
        'series_title' => 'My Show',
    ]);

    Event::assertDispatched(EmbyPlaybackUpdated::class);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('playback.start upserts an existing in-progress row instead of duplicating', function (): void {
    $payload = [
        'Event' => 'playback.start',
        'User' => ['Id' => 'emby-user-1'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Movie', 'Name' => 'Movie 1', 'RunTimeTicks' => 9000000000],
        'PlaybackInfo' => ['PositionTicks' => 1000000000, 'PlayedToCompletion' => false],
    ];

    resolve(EmbyWebhookHandler::class)->handle(makeWebhookEvent($this->connection, $payload));
    $payload['PlaybackInfo']['PositionTicks'] = 2000000000;
    resolve(EmbyWebhookHandler::class)->handle(makeWebhookEvent($this->connection, $payload));

    expect(EmbyActivity::where('emby_item_id', 'item-1')->count())->toBe(1);
    expect(EmbyActivity::where('emby_item_id', 'item-1')->first()->play_position)->toBe(2000000000);
});

test('playback.stop with PlayedToCompletion=true produces a finished row', function (): void {
    $webhookEvent = makeWebhookEvent($this->connection, [
        'Event' => 'playback.stop',
        'User' => ['Id' => 'emby-user-1'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Movie', 'Name' => 'Movie 1', 'RunTimeTicks' => 9000000000],
        'PlaybackInfo' => ['PositionTicks' => 9000000000, 'PlayedToCompletion' => true],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('emby_activities', [
        'emby_user_link_id' => $this->userLink->id,
        'action' => 'finished',
    ]);
});

test('playback.stop without completion produces a stopped row', function (): void {
    $webhookEvent = makeWebhookEvent($this->connection, [
        'Event' => 'playback.stop',
        'User' => ['Id' => 'emby-user-1'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Movie', 'Name' => 'Movie 1'],
        'PlaybackInfo' => ['PositionTicks' => 1000, 'PlayedToCompletion' => false],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('emby_activities', [
        'action' => 'stopped',
    ]);
});

test('item.markplayed produces a finished row', function (): void {
    $webhookEvent = makeWebhookEvent($this->connection, [
        'Event' => 'item.markplayed',
        'User' => ['Id' => 'emby-user-1'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Movie', 'Name' => 'Movie 1'],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    $this->assertDatabaseHas('emby_activities', ['action' => 'finished']);
});

test('terminal events insert new rows (do not upsert)', function (): void {
    $payload = [
        'Event' => 'playback.stop',
        'User' => ['Id' => 'emby-user-1'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Movie', 'Name' => 'Movie 1'],
        'PlaybackInfo' => ['PositionTicks' => 100, 'PlayedToCompletion' => false],
    ];

    resolve(EmbyWebhookHandler::class)->handle(makeWebhookEvent($this->connection, $payload));
    resolve(EmbyWebhookHandler::class)->handle(makeWebhookEvent($this->connection, $payload));

    expect(EmbyActivity::where('emby_item_id', 'item-1')->where('action', 'stopped')->count())->toBe(2);
});

test('handler skips when no EmbyUserLink exists for the Emby user', function (): void {
    $webhookEvent = makeWebhookEvent($this->connection, [
        'Event' => 'playback.start',
        'User' => ['Id' => 'unknown-emby-user'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Movie', 'Name' => 'X'],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    expect(EmbyActivity::count())->toBe(0);
    Event::assertNotDispatched(EmbyPlaybackUpdated::class);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('handler ignores unsupported event types', function (): void {
    $webhookEvent = makeWebhookEvent($this->connection, [
        'Event' => 'library.new',
        'User' => ['Id' => 'emby-user-1'],
        'Item' => ['Id' => 'item-1', 'Type' => 'Movie', 'Name' => 'X'],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    expect(EmbyActivity::count())->toBe(0);
    Event::assertNotDispatched(EmbyPlaybackUpdated::class);
    expect($webhookEvent->fresh()->processed_at)->not->toBeNull();
});

test('handler ignores unsupported media types', function (): void {
    $webhookEvent = makeWebhookEvent($this->connection, [
        'Event' => 'playback.start',
        'User' => ['Id' => 'emby-user-1'],
        'Item' => ['Id' => 'item-1', 'Type' => 'MusicAlbum', 'Name' => 'X'],
    ]);

    resolve(EmbyWebhookHandler::class)->handle($webhookEvent);

    expect(EmbyActivity::count())->toBe(0);
    Event::assertNotDispatched(EmbyPlaybackUpdated::class);
});
