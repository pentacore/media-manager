<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->seerr = new SeerrClient(ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'k',
    ]));
});

test('discoverTrending reads the trending endpoint and caches the page', function (): void {
    Http::fake(['seerr.local:5055/api/v1/discover/trending*' => Http::response(['results' => [['id' => 1, 'mediaType' => 'movie', 'title' => 'Dune']]])]);

    $first = $this->seerr->discoverTrending();
    $second = $this->seerr->discoverTrending();

    expect($first['results'][0]['title'])->toBe('Dune')->and($second)->toBe($first);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/discover/trending') && $request['page'] === 1);
});

test('discoverUpcoming routes movies and tv to their own upcoming endpoints', function (string $mediaType, string $path): void {
    Http::fake(['seerr.local:5055/api/v1/discover/*' => Http::response(['results' => []])]);

    $this->seerr->discoverUpcoming($mediaType);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $path));
})->with([
    'movie' => ['movie', '/api/v1/discover/movies/upcoming'],
    'tv' => ['tv', '/api/v1/discover/tv/upcoming'],
]);

test('discoverUpcoming caches each media type and page separately', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/discover/movies/upcoming*' => Http::response(['results' => [['id' => 1, 'mediaType' => 'movie', 'title' => 'Dune']]]),
        'seerr.local:5055/api/v1/discover/tv/upcoming*' => Http::response(['results' => [['id' => 2, 'mediaType' => 'tv', 'name' => 'Andor']]]),
    ]);

    $first = $this->seerr->discoverUpcoming('movie');
    $second = $this->seerr->discoverUpcoming('movie');

    expect($second)->toBe($first)
        ->and($first['results'][0]['title'])->toBe('Dune');
    Http::assertSentCount(1);

    expect($this->seerr->discoverUpcoming('tv')['results'][0]['name'])->toBe('Andor');
    $this->seerr->discoverUpcoming('movie', page: 2);

    Http::assertSentCount(3);
});

test('discoverUpcoming rejects an unknown media type', function (): void {
    expect(fn (): array => $this->seerr->discoverUpcoming('person'))->toThrow(InvalidArgumentException::class);
});

test('getRequestsByUser filters Seerr requests by the requesting user', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['pages' => 1], 'results' => []])]);

    $this->seerr->getRequestsByUser(7, take: 20, skip: 40);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/request')
        && $request['requestedBy'] === 7
        && $request['take'] === 20
        && $request['skip'] === 40
        && $request['filter'] === 'all');
});
