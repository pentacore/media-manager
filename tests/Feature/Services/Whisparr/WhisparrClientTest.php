<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrUnexpectedResponse;
use App\Services\Whisparr\WhisparrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('getEpisodes reads one v2 series episode list and caches it', function (): void {
    $connection = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k',
    ]);
    Http::fake(['whisparr.local:6969/api/v3/episode*' => Http::response([['id' => 51, 'seasonNumber' => 2024]])]);

    $whisparrClient = new WhisparrClient($connection);

    expect($whisparrClient->getEpisodes(5))->toBe([['id' => 51, 'seasonNumber' => 2024]])
        ->and($whisparrClient->getEpisodes(5))->toBe([['id' => 51, 'seasonNumber' => 2024]]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/episode?seriesId=5'));
});

test('an episode list with a null or scalar entry skips it and still returns the valid scenes', function (): void {
    $connection = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k',
    ]);
    Http::fake(['whisparr.local:6969/api/v3/episode*' => Http::response([['id' => 1], null, 'not-an-episode', 42])]);

    expect(new WhisparrClient($connection)->getEpisodes(5))->toBe([['id' => 1]]);
});

test('an object-shaped episode body yields no scenes without throwing', function (): void {
    $connection = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k',
    ]);
    Http::fake(['whisparr.local:6969/api/v3/episode*' => Http::response(['error' => 'Bad Request'])]);

    expect(new WhisparrClient($connection)->getEpisodes(5))->toBe([]);
});

test('a 200 read whose body is not JSON data throws instead of reading as empty, and is not cached', function (string $method, string $url, string $body): void {
    $connection = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k',
    ]);
    Http::fake([$url => Http::response($body, 200, ['Content-Type' => str_starts_with($body, '<') ? 'text/html' : 'application/json'])]);

    $whisparrClient = new WhisparrClient($connection);

    expect(fn (): array => $whisparrClient->{$method}(...($method === 'getItemById' ? [11] : ($method === 'searchItems' ? ['tmdb:1'] : []))))
        ->toThrow(ArrUnexpectedResponse::class, 'Whisparr answered with a body that is not JSON data.')
        ->and(fn (): array => $whisparrClient->{$method}(...($method === 'getItemById' ? [11] : ($method === 'searchItems' ? ['tmdb:1'] : []))))
        ->toThrow(ArrUnexpectedResponse::class);

    // Both calls went upstream: the failure never entered the cache.
    Http::assertSentCount(2);
})->with([
    'list, HTML' => ['getItems', 'whisparr.local:6969/api/v3/movie', '<html>Sign in</html>'],
    'list, scalar' => ['getItems', 'whisparr.local:6969/api/v3/movie', '42'],
    'item, HTML' => ['getItemById', 'whisparr.local:6969/api/v3/movie/11', '<html>Sign in</html>'],
    'item, scalar' => ['getItemById', 'whisparr.local:6969/api/v3/movie/11', 'true'],
    'search, scalar' => ['searchItems', 'whisparr.local:6969/api/v3/movie/lookup*', '"just a string"'],
    'quality profiles, HTML' => ['getQualityProfiles', 'whisparr.local:6969/api/v3/qualityprofile', '<html>Sign in</html>'],
]);
