<?php

declare(strict_types=1);

use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
    config()->set('services.tmdb.api_key', 'test-bearer-token');
});

test('getTitle hits /movie/{id} for a movie and returns parsed json', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205*' => Http::response([
            'id' => 27205, 'title' => 'Inception', 'release_date' => '2010-07-15',
        ]),
    ]);

    $payload = (new TmdbClient)->getTitle(27205, 'movie');

    expect($payload['title'])->toBe('Inception');
    Http::assertSent(fn ($r): bool => $r->hasHeader('Authorization', 'Bearer test-bearer-token')
        && str_contains((string) $r->url(), '/movie/27205'));
});

test('getTitle hits /tv/{id} for a series', function (): void {
    Http::fake([
        'api.themoviedb.org/3/tv/1399*' => Http::response([
            'id' => 1399, 'name' => 'Game of Thrones',
        ]),
    ]);

    $payload = (new TmdbClient)->getTitle(1399, 'tv');

    expect($payload['name'])->toBe('Game of Thrones');
});

test('getTitle rejects unknown media_type', function (): void {
    expect(fn (): array => (new TmdbClient)->getTitle(1, 'bogus'))
        ->toThrow(InvalidArgumentException::class);
});

test('getTitle throws when API key is not configured', function (): void {
    config()->set('services.tmdb.api_key');

    expect(fn (): array => (new TmdbClient)->getTitle(27205, 'movie'))
        ->toThrow(RuntimeException::class, 'TMDB API key is not configured.');
});

test('getSimilar returns the results array for a movie', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205/similar*' => Http::response([
            'page' => 1,
            'results' => [
                ['id' => 1234, 'title' => 'Similar Movie'],
            ],
        ]),
    ]);

    $payload = (new TmdbClient)->getSimilar(27205, 'movie');

    expect($payload['results'])->toHaveCount(1);
    expect($payload['results'][0]['title'])->toBe('Similar Movie');
});

test('getSimilar uses the TV endpoint for media_type tv', function (): void {
    Http::fake([
        'api.themoviedb.org/3/tv/1399/similar*' => Http::response(['results' => []]),
    ]);

    (new TmdbClient)->getSimilar(1399, 'tv');

    Http::assertSent(fn ($r): bool => str_contains((string) $r->url(), '/tv/1399/similar'));
});

test('getCredits returns cast and crew', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205/credits*' => Http::response([
            'cast' => [['name' => 'Leonardo DiCaprio', 'character' => 'Cobb']],
            'crew' => [['name' => 'Christopher Nolan', 'job' => 'Director']],
        ]),
    ]);

    $payload = (new TmdbClient)->getCredits(27205, 'movie');

    expect($payload['cast'][0]['name'])->toBe('Leonardo DiCaprio');
    expect($payload['crew'][0]['job'])->toBe('Director');
});
