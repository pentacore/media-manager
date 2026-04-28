<?php

declare(strict_types=1);

use App\Services\Trakt\TraktClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::store('array')->flush();
    Http::preventStrayRequests();
    config()->set('services.trakt.base_url', 'https://api.trakt.tv');
    config()->set('services.trakt.client_id', 'trakt-test-id');
});

test('getTrending hits HTTP only once per media_type', function (): void {
    Http::fake([
        'api.trakt.tv/movies/trending*' => Http::response([
            ['watchers' => 53, 'movie' => ['title' => 'Inception']],
        ]),
    ]);

    $client = new TraktClient;
    $client->getTrending('movie');
    $client->getTrending('movie');

    Http::assertSentCount(1);
});

test('getTrending hits HTTP twice for movie vs tv', function (): void {
    Http::fake([
        'api.trakt.tv/movies/trending*' => Http::response([
            ['watchers' => 1, 'movie' => ['title' => 'X']],
        ]),
        'api.trakt.tv/shows/trending*' => Http::response([
            ['watchers' => 1, 'show' => ['title' => 'Y']],
        ]),
    ]);

    $client = new TraktClient;
    $client->getTrending('movie');
    $client->getTrending('tv');

    Http::assertSentCount(2);
});

test('getPopular caches separately from getTrending', function (): void {
    Http::fake([
        'api.trakt.tv/movies/trending*' => Http::response([['movie' => ['title' => 'A']]]),
        'api.trakt.tv/movies/popular*' => Http::response([['title' => 'B']]),
    ]);

    $client = new TraktClient;
    $client->getTrending('movie');
    $client->getPopular('movie');
    $client->getPopular('movie');

    Http::assertSentCount(2); // 1 trending, 1 popular (second popular cached)
});

test('getList caches per-list-id', function (): void {
    Http::fake([
        'api.trakt.tv/lists/777/items*' => Http::response([['type' => 'show']]),
    ]);

    $client = new TraktClient;
    $client->getList(777);
    $client->getList(777);

    Http::assertSentCount(1);
});
