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
    expect(fn () => (new TmdbClient)->getTitle(1, 'bogus'))
        ->toThrow(InvalidArgumentException::class);
});

test('getTitle throws when API key is not configured', function (): void {
    config()->set('services.tmdb.api_key', null);

    expect(fn () => (new TmdbClient)->getTitle(27205, 'movie'))
        ->toThrow(RuntimeException::class, 'TMDB API key is not configured.');
});
