<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
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
