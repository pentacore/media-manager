<?php

declare(strict_types=1);

use App\Services\Trakt\TraktClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('services.trakt.base_url', 'https://api.trakt.tv');
    config()->set('services.trakt.client_id', 'trakt-test-id');
});

test('getTrending hits /movies/trending for movie media_type', function (): void {
    Http::fake([
        'api.trakt.tv/movies/trending*' => Http::response([
            ['watchers' => 53, 'movie' => ['title' => 'Inception', 'year' => 2010]],
        ]),
    ]);

    $payload = (new TraktClient)->getTrending('movie');

    expect($payload[0]['movie']['title'])->toBe('Inception');
    Http::assertSent(fn ($r): bool => $r->hasHeader('trakt-api-key', 'trakt-test-id')
        && $r->hasHeader('trakt-api-version', '2')
        && str_contains((string) $r->url(), '/movies/trending'));
});

test('getTrending hits /shows/trending for tv', function (): void {
    Http::fake([
        'api.trakt.tv/shows/trending*' => Http::response([
            ['watchers' => 22, 'show' => ['title' => 'Severance', 'year' => 2022]],
        ]),
    ]);

    $payload = (new TraktClient)->getTrending('tv');

    expect($payload[0]['show']['title'])->toBe('Severance');
});

test('getPopular hits /movies/popular', function (): void {
    Http::fake([
        'api.trakt.tv/movies/popular*' => Http::response([
            ['title' => 'The Shawshank Redemption', 'year' => 1994],
        ]),
    ]);

    $payload = (new TraktClient)->getPopular('movie');

    expect($payload[0]['title'])->toBe('The Shawshank Redemption');
});

test('getList hits /lists/{id}/items', function (): void {
    Http::fake([
        'api.trakt.tv/lists/12345/items*' => Http::response([
            ['type' => 'movie', 'movie' => ['title' => 'Inception']],
        ]),
    ]);

    $payload = (new TraktClient)->getList(12345);

    expect($payload[0]['movie']['title'])->toBe('Inception');
});

test('rejects unknown media_type', function (): void {
    expect(fn () => (new TraktClient)->getTrending('podcast'))
        ->toThrow(InvalidArgumentException::class);
});

test('throws when client_id is missing', function (): void {
    config()->set('services.trakt.client_id', null);

    expect(fn () => (new TraktClient)->getTrending('movie'))
        ->toThrow(RuntimeException::class, 'Trakt client id is not configured.');
});
