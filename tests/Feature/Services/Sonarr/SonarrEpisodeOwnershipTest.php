<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use App\Services\Sonarr\SonarrEpisodeOwnership;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->sonarrClient = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']));
});

function sonarrEpisodeOwnershipFake(): void
{
    Http::fake(['sonarr.local:8989/api/v3/episode?seriesId=7*' => Http::response([
        ['id' => 70, 'seriesId' => 7, 'seasonNumber' => 1],
        ['id' => 71, 'seriesId' => 7, 'seasonNumber' => 1],
        ['id' => 80, 'seriesId' => 7, 'seasonNumber' => 2],
    ])]);
}

test('episodes of the series belong to it', function (?int $seasonNumber, array $episodeIds): void {
    sonarrEpisodeOwnershipFake();

    expect(resolve(SonarrEpisodeOwnership::class)->allBelongTo($this->sonarrClient, 7, $episodeIds, $seasonNumber))->toBeTrue();
})->with([
    'any season' => [null, [70, 80]],
    'their own season' => [1, [70, 71]],
]);

test('an id that is not an episode of the series, or of the named season, does not belong', function (?int $seasonNumber, array $episodeIds): void {
    sonarrEpisodeOwnershipFake();

    expect(resolve(SonarrEpisodeOwnership::class)->allBelongTo($this->sonarrClient, 7, $episodeIds, $seasonNumber))->toBeFalse();
})->with([
    'another series' => [null, [70, 999]],
    'another season' => [1, [70, 80]],
    'no ids' => [null, []],
]);

test('an episode added after the list was cached is found by one uncached re-read', function (): void {
    $upstream = new stdClass;
    $upstream->episodes = [['id' => 70, 'seriesId' => 7, 'seasonNumber' => 1]];
    $upstream->reads = 0;
    Http::fake(['sonarr.local:8989/api/v3/episode?seriesId=7*' => function () use ($upstream) {
        $upstream->reads++;

        return Http::response($upstream->episodes);
    }]);

    $this->sonarrClient->getEpisodesBySeries(7);
    $upstream->episodes[] = ['id' => 72, 'seriesId' => 7, 'seasonNumber' => 1];

    expect(resolve(SonarrEpisodeOwnership::class)->allBelongTo($this->sonarrClient, 7, [72]))->toBeTrue()
        ->and($upstream->reads)->toBe(2);
});

test('ids the cached list already covers need no second read', function (): void {
    sonarrEpisodeOwnershipFake();

    resolve(SonarrEpisodeOwnership::class)->allBelongTo($this->sonarrClient, 7, [70]);
    resolve(SonarrEpisodeOwnership::class)->allBelongTo($this->sonarrClient, 7, [71]);

    Http::assertSentCount(1);
});

test('an unreachable Sonarr is an error, not a refusal', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/episode*' => fn (): never => throw new ConnectionException('Connection refused.')]);

    expect(fn (): bool => resolve(SonarrEpisodeOwnership::class)->allBelongTo($this->sonarrClient, 7, [70]))
        ->toThrow(ConnectionException::class);
});
