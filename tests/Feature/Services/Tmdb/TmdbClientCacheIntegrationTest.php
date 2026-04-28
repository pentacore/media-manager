<?php

declare(strict_types=1);

use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::store('array')->flush();
    Http::preventStrayRequests();
    config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
    config()->set('services.tmdb.api_key', 'test-bearer-token');
});

test('getTitle hits HTTP only once per tmdb_id+media_type', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205*' => Http::response([
            'id' => 27205, 'title' => 'Inception',
        ]),
    ]);

    $client = new TmdbClient;
    $client->getTitle(27205, 'movie');
    $client->getTitle(27205, 'movie');

    Http::assertSentCount(1);
});

test('getTitle hits HTTP twice for different tmdb_ids', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205*' => Http::response(['id' => 27205, 'title' => 'A']),
        'api.themoviedb.org/3/movie/12345*' => Http::response(['id' => 12345, 'title' => 'B']),
    ]);

    $client = new TmdbClient;
    $client->getTitle(27205, 'movie');
    $client->getTitle(12345, 'movie');

    Http::assertSentCount(2);
});

test('getTitle keys movie and tv separately', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/1*' => Http::response(['id' => 1, 'title' => 'M']),
        'api.themoviedb.org/3/tv/1*' => Http::response(['id' => 1, 'name' => 'T']),
    ]);

    $client = new TmdbClient;
    $client->getTitle(1, 'movie');
    $client->getTitle(1, 'tv');

    Http::assertSentCount(2);
});

test('getSimilar caches separately from getTitle', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205' => Http::response(['id' => 27205]),
        'api.themoviedb.org/3/movie/27205/similar*' => Http::response(['results' => []]),
    ]);

    $client = new TmdbClient;
    $client->getTitle(27205, 'movie');
    $client->getSimilar(27205, 'movie');
    $client->getSimilar(27205, 'movie');

    Http::assertSentCount(2); // 1 for getTitle, 1 for getSimilar (second is cached)
});

test('getCredits caches', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205/credits*' => Http::response(['cast' => []]),
    ]);

    $client = new TmdbClient;
    $client->getCredits(27205, 'movie');
    $client->getCredits(27205, 'movie');

    Http::assertSentCount(1);
});
