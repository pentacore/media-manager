<?php

declare(strict_types=1);

use App\Models\ActionTypeConfig;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Sonarr\SonarrWebhookHandler;

beforeEach(function (): void {
    $this->connection = ServiceConnection::factory()->sonarr()->create();
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);
});

test('SeriesAdd webhook upserts an IndexedSeries row', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'SeriesAdd',
        'payload' => [
            'eventType' => 'SeriesAdd',
            'series' => [
                'id' => 501,
                'tvdbId' => 9001,
                'imdbId' => 'tt1234567',
                'title' => 'Indexed Series',
                'sortTitle' => 'indexed series',
                'year' => 2024,
                'titleSlug' => 'indexed-series',
                'status' => 'continuing',
                'monitored' => true,
                'network' => 'HBO',
                'genres' => ['Drama'],
                'overview' => 'Indexed.',
                'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://example.com/p.jpg']],
                'added' => '2024-01-01T00:00:00Z',
            ],
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    $row = IndexedSeries::query()
        ->where('service_connection_id', $this->connection->id)
        ->where('sonarr_id', 501)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->title)->toBe('Indexed Series');
    expect($row->tvdb_id)->toBe(9001);
    expect($row->imdb_id)->toBe(1234567);
    expect($row->genres)->toBe(['Drama']);
    expect($row->poster_url)->toBe('https://example.com/p.jpg');
    expect($row->monitored)->toBeTrue();
});

test('repeated SeriesAdd webhook is idempotent', function (): void {
    $payload = [
        'eventType' => 'SeriesAdd',
        'series' => ['id' => 502, 'title' => 'Same'],
    ];

    foreach (range(1, 2) as $_) {
        $webhookEvent = WebhookEvent::factory()->create([
            'service_connection_id' => $this->connection->id,
            'event_type' => 'SeriesAdd',
            'payload' => $payload,
        ]);
        resolve(SonarrWebhookHandler::class)->handle($webhookEvent);
    }

    expect(IndexedSeries::query()->where('sonarr_id', 502)->count())->toBe(1);
});

test('SeriesDelete webhook removes the IndexedSeries row', function (): void {
    IndexedSeries::factory()->for($this->connection, 'serviceConnection')->create([
        'sonarr_id' => 503,
        'title' => 'To Delete',
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'SeriesDelete',
        'payload' => [
            'eventType' => 'SeriesDelete',
            'series' => ['id' => 503, 'title' => 'To Delete'],
        ],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(IndexedSeries::query()->where('sonarr_id', 503)->exists())->toBeFalse();
});

test('SeriesAdd webhook with empty series payload does not write a row', function (): void {
    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $this->connection->id,
        'event_type' => 'SeriesAdd',
        'payload' => ['eventType' => 'SeriesAdd'],
    ]);

    resolve(SonarrWebhookHandler::class)->handle($webhookEvent);

    expect(IndexedSeries::query()->count())->toBe(0);
});
