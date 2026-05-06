<?php

declare(strict_types=1);

use App\Models\ActionTypeConfig;
use App\Models\IndexedMovie;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Radarr\RadarrWebhookHandler;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->radarr()->create();
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);
});

test('MovieAdded webhook upserts an IndexedMovie row', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MovieAdded',
        'payload' => [
            'eventType' => 'MovieAdded',
            'movie' => [
                'id' => 700,
                'tmdbId' => 808,
                'imdbId' => 'tt7654321',
                'title' => 'Indexed Movie',
                'sortTitle' => 'indexed movie',
                'originalTitle' => 'Indexed Movie',
                'year' => 2022,
                'titleSlug' => 'indexed-movie',
                'status' => 'released',
                'monitored' => true,
                'hasFile' => true,
                'genres' => ['Action'],
                'overview' => 'Indexed.',
                'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://example.com/m.jpg']],
                'added' => '2022-06-01T00:00:00Z',
            ],
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    $row = IndexedMovie::query()
        ->where('service_connection_id', $this->connection->id)
        ->where('radarr_id', 700)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->title)->toBe('Indexed Movie');
    expect($row->tmdb_id)->toBe(808);
    expect($row->imdb_id)->toBe('tt7654321');
    expect($row->genres)->toBe(['Action']);
    expect($row->has_file)->toBeTrue();
    expect($row->poster_url)->toBe('https://example.com/m.jpg');
});

test('repeated MovieAdded webhook is idempotent', function (): void {
    $payload = [
        'eventType' => 'MovieAdded',
        'movie' => ['id' => 701, 'title' => 'Same'],
    ];

    foreach (range(1, 2) as $_) {
        $webhookEvent = WebhookEvent::factory()->create([
            'service_connection_id' => $this->connection->id,
            'event_type' => 'MovieAdded',
            'payload' => $payload,
        ]);
        resolve(RadarrWebhookHandler::class)->handle($webhookEvent);
    }

    expect(IndexedMovie::query()->where('radarr_id', 701)->count())->toBe(1);
});

test('MovieDelete webhook removes the IndexedMovie row', function (): void {
    IndexedMovie::factory()->for($this->connection, 'serviceConnection')->create([
        'radarr_id' => 702,
        'title' => 'To Delete',
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'MovieDelete',
        'payload' => [
            'eventType' => 'MovieDelete',
            'movie' => ['id' => 702, 'title' => 'To Delete'],
        ],
    ]);

    resolve(RadarrWebhookHandler::class)->handle($webhookEvent);

    expect(IndexedMovie::query()->where('radarr_id', 702)->exists())->toBeFalse();
});
