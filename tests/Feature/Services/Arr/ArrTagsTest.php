<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Pinned and store-scoped the way SonarrClientCacheIntegrationTest does it: the
    // assertions below turn on a cache hit, so they must not depend on whatever store
    // and TTLs the ambient env happens to supply. Cache::flush() on the default store
    // worked only because phpunit.xml sets CACHE_STORE=array — true today, and a
    // confusing failure the moment it is not.
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
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

    // Content asserted, not just the count: the Radarr override is its own method, so
    // a count-only assertion would let a shape regression through on this side while
    // the Sonarr test kept passing.
    expect($client->getTags())->toHaveCount(1)
        ->and($client->getTags()[0]['id'])->toBe(5)
        ->and($client->getTags()[0]['label'])->toBe('sub-check');

    $client->getTags();

    Http::assertSentCount(1);
});
