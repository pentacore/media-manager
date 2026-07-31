<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
});

test('sonarr tags are fetched and cached', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    Http::fake(['sonarr.local:8989/api/v3/tag' => Http::response([
        ['id' => 1, 'label' => 'sub-check'],
        ['id' => 2, 'label' => 'anime'],
    ])]);

    $client = new SonarrClient($connection);

    expect($client->getTags())->toHaveCount(2)
        ->and($client->getTags()[0]['label'])->toBe('sub-check');

    Http::assertSentCount(1);
});

test('radarr tags are fetched and cached', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
    ]);

    Http::fake(['radarr.local:7878/api/v3/tag' => Http::response([
        ['id' => 5, 'label' => 'sub-check'],
    ])]);

    $client = new RadarrClient($connection);

    expect($client->getTags())->toHaveCount(1);

    $client->getTags();

    Http::assertSentCount(1);
});
