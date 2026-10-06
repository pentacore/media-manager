<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Anime\SeasonLibraryStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    $this->radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
});

/**
 * @return array<string, mixed>
 */
function seasonLibraryRow(string $key, string $mediaType, ?int $tmdbId, ?int $tvdbId = null, ?int $tvdbSeason = null): array
{
    return ['key' => $key, 'mapping' => ['tmdbId' => $tmdbId, 'mediaType' => $mediaType, 'tvdbId' => $tvdbId, 'tmdbSeason' => null, 'tvdbSeason' => $tvdbSeason, 'mapped' => $tmdbId !== null]];
}

/**
 * @param  array<int, array{seasonNumber: int, monitored: bool}>  $seasons
 * @return array<string, mixed>
 */
function seasonLibrarySeries(int $id, bool $monitored, array $seasons): array
{
    return ['id' => $id, 'title' => 'Show', 'monitored' => $monitored, 'seasons' => $seasons];
}

test('a monitored series whose season is monitored is in the library', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, true, [['seasonNumber' => 2, 'monitored' => true]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2)]);

    expect($result['anilist:1'])->toBe([
        'status' => 'in_library',
        'unmonitoredScope' => null,
        'library' => ['service' => 'sonarr', 'itemId' => 7, 'connectionId' => $this->sonarr->id, 'seasonNumber' => 2, 'onActiveConnection' => true],
    ]);
});

test('an unmonitored series is unmonitored at series scope', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, false, [['seasonNumber' => 2, 'monitored' => true]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2)]);

    expect($result['anilist:1'])->toBe([
        'status' => 'unmonitored',
        'unmonitoredScope' => 'series',
        'library' => ['service' => 'sonarr', 'itemId' => 7, 'connectionId' => $this->sonarr->id, 'seasonNumber' => 2, 'onActiveConnection' => true],
    ]);
});

test('an unmonitored season of a monitored series is unmonitored at season scope', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, true, [['seasonNumber' => 1, 'monitored' => true], ['seasonNumber' => 2, 'monitored' => false]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2)]);

    expect($result['anilist:1'])->toBe([
        'status' => 'unmonitored',
        'unmonitoredScope' => 'season',
        'library' => ['service' => 'sonarr', 'itemId' => 7, 'connectionId' => $this->sonarr->id, 'seasonNumber' => 2, 'onActiveConnection' => true],
    ]);
});

test('season 0 is judged at series level, so an unmonitored Specials season never flags a monitored series', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, true, [['seasonNumber' => 0, 'monitored' => false], ['seasonNumber' => 1, 'monitored' => true]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 0)]);

    expect($result['anilist:1']['status'])->toBe('in_library')
        ->and($result['anilist:1']['unmonitoredScope'])->toBeNull()
        ->and($result['anilist:1']['library']['seasonNumber'])->toBeNull();
});

test('season 0 of an unmonitored series is flagged at series level without a season number', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => false]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, false, [['seasonNumber' => 0, 'monitored' => false], ['seasonNumber' => 1, 'monitored' => false]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 0)]);

    expect($result['anilist:1']['status'])->toBe('unmonitored')
        ->and($result['anilist:1']['unmonitoredScope'])->toBe('series')
        ->and($result['anilist:1']['library']['seasonNumber'])->toBeNull();
});

test('an unknown tvdb season is judged at series level only', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, true, [['seasonNumber' => 1, 'monitored' => true], ['seasonNumber' => 2, 'monitored' => false]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189)]);

    expect($result['anilist:1']['status'])->toBe('in_library')
        ->and($result['anilist:1']['unmonitoredScope'])->toBeNull()
        ->and($result['anilist:1']['library']['seasonNumber'])->toBeNull();
});

test('a season Sonarr does not list yet leaves the series-level verdict and no season number', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, true, [['seasonNumber' => 1, 'monitored' => false], ['seasonNumber' => 2, 'monitored' => false]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 3)]);

    expect($result['anilist:1']['status'])->toBe('in_library')
        ->and($result['anilist:1']['unmonitoredScope'])->toBeNull()
        ->and($result['anilist:1']['library']['seasonNumber'])->toBeNull();
});

test('two entries for different seasons of one series share a single Sonarr lookup', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, true, [['seasonNumber' => 1, 'monitored' => true], ['seasonNumber' => 2, 'monitored' => false]]))]);

    $result = (new SeasonLibraryStatus)->resolve([
        seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 1),
        seasonLibraryRow('anilist:2', 'tv', 1396, 81189, 2),
    ]);

    expect($result['anilist:1']['status'])->toBe('in_library')
        ->and($result['anilist:2']['status'])->toBe('unmonitored')
        ->and($result['anilist:2']['unmonitoredScope'])->toBe('season');
    Http::assertSentCount(1);
});

test('a Sonarr outage falls back to the indexed monitored flag for each series, asking once per series', function (): void {
    Sleep::fake();
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => false]);
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 8, 'tvdb_id' => 81190, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/*' => Http::response(null, 503)]);

    $result = (new SeasonLibraryStatus)->resolve([
        seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2),
        seasonLibraryRow('anilist:2', 'tv', 1397, 81190, 1),
    ]);

    expect($result['anilist:1']['status'])->toBe('unmonitored')
        ->and($result['anilist:1']['unmonitoredScope'])->toBe('series')
        ->and($result['anilist:1']['library']['seasonNumber'])->toBeNull()
        ->and($result['anilist:2']['status'])->toBe('in_library')
        ->and($result['anilist:2']['library']['seasonNumber'])->toBeNull();
    // Both series are asked for, each exactly once: no retries.
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/series/7')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/series/8')))->toHaveCount(1);
});

test('a series Sonarr no longer has falls back to the index without stopping other lookups', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => false]);
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 8, 'tvdb_id' => 81190, 'monitored' => true]);
    Http::fake([
        'sonarr.local:8989/api/v3/series/7' => Http::response(['message' => 'NotFound'], 404),
        'sonarr.local:8989/api/v3/series/8' => Http::response(seasonLibrarySeries(8, true, [['seasonNumber' => 2, 'monitored' => false]])),
    ]);

    $result = (new SeasonLibraryStatus)->resolve([
        seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2),
        seasonLibraryRow('anilist:2', 'tv', 1397, 81190, 2),
    ]);

    expect($result['anilist:1']['status'])->toBe('unmonitored')
        ->and($result['anilist:1']['unmonitoredScope'])->toBe('series')
        ->and($result['anilist:1']['library']['seasonNumber'])->toBeNull()
        ->and($result['anilist:2']['status'])->toBe('unmonitored')
        ->and($result['anilist:2']['unmonitoredScope'])->toBe('season')
        ->and($result['anilist:2']['library']['seasonNumber'])->toBe(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/series/8'));
});

test('a Sonarr answer that is not a series falls back to the index for that series only', function (): void {
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => false]);
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 8, 'tvdb_id' => 81190, 'monitored' => true]);
    Http::fake([
        'sonarr.local:8989/api/v3/series/7' => Http::response(['message' => 'NotFound']),
        'sonarr.local:8989/api/v3/series/8' => Http::response(seasonLibrarySeries(8, true, [['seasonNumber' => 1, 'monitored' => false]])),
    ]);

    $result = (new SeasonLibraryStatus)->resolve([
        seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2),
        seasonLibraryRow('anilist:2', 'tv', 1397, 81190, 1),
    ]);

    expect($result['anilist:1']['status'])->toBe('unmonitored')
        ->and($result['anilist:1']['unmonitoredScope'])->toBe('series')
        ->and($result['anilist:1']['library']['seasonNumber'])->toBeNull()
        ->and($result['anilist:2']['status'])->toBe('unmonitored')
        ->and($result['anilist:2']['unmonitoredScope'])->toBe('season')
        ->and($result['anilist:2']['library']['seasonNumber'])->toBe(1);
    Http::assertSentCount(2);
});

test('a series owned only on a non-active Sonarr connection is not openable from the card', function (): void {
    $inactiveSonarr = ServiceConnection::factory()->sonarr()->inactive()->create(['url' => 'http://sonarr-4k.local:8989', 'api_key' => 'k']);
    IndexedSeries::factory()->for($inactiveSonarr, 'serviceConnection')->create(['sonarr_id' => 9, 'tvdb_id' => 81189, 'monitored' => false]);
    Http::fake();

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2)]);

    expect($result['anilist:1'])->toBe([
        'status' => 'unmonitored',
        'unmonitoredScope' => 'series',
        'library' => ['service' => 'sonarr', 'itemId' => 9, 'connectionId' => $inactiveSonarr->id, 'seasonNumber' => null, 'onActiveConnection' => false],
    ]);
    Http::assertNothingSent();
});

test('a series indexed on both connections resolves against the active one', function (): void {
    $inactiveSonarr = ServiceConnection::factory()->sonarr()->inactive()->create(['url' => 'http://sonarr-4k.local:8989', 'api_key' => 'k']);
    IndexedSeries::factory()->for($inactiveSonarr, 'serviceConnection')->create(['sonarr_id' => 9, 'tvdb_id' => 81189, 'monitored' => false]);
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr.local:8989/api/v3/series/7' => Http::response(seasonLibrarySeries(7, true, [['seasonNumber' => 2, 'monitored' => true]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2)]);

    expect($result['anilist:1']['status'])->toBe('in_library')
        ->and($result['anilist:1']['library']['itemId'])->toBe(7)
        ->and($result['anilist:1']['library']['onActiveConnection'])->toBeTrue();
    Http::assertSentCount(1);
});

test('a series held only on a second active Sonarr connection is read live from it but is not on the primary', function (): void {
    $secondSonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-anime.local:8989', 'api_key' => 'k']);
    IndexedSeries::factory()->for($secondSonarr, 'serviceConnection')->create(['sonarr_id' => 9, 'tvdb_id' => 81189, 'monitored' => true]);
    Http::fake(['sonarr-anime.local:8989/api/v3/series/9' => Http::response(seasonLibrarySeries(9, true, [['seasonNumber' => 2, 'monitored' => false]]))]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:1', 'tv', 1396, 81189, 2)]);

    expect($result['anilist:1'])->toBe([
        'status' => 'unmonitored',
        'unmonitoredScope' => 'season',
        'library' => ['service' => 'sonarr', 'itemId' => 9, 'connectionId' => $secondSonarr->id, 'seasonNumber' => 2, 'onActiveConnection' => false],
    ]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://sonarr-anime.local:8989/api/v3/series/9');
    Http::assertSentCount(1);
});

test('an unmonitored Radarr movie is unmonitored at movie scope', function (): void {
    IndexedMovie::factory()->for($this->radarr, 'serviceConnection')->create(['radarr_id' => 10, 'tmdb_id' => 4935, 'monitored' => true]);
    Http::fake(['radarr.local:7878/api/v3/movie/10' => Http::response(['id' => 10, 'title' => 'Movie', 'monitored' => false])]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:3', 'movie', 4935)]);

    expect($result['anilist:3'])->toBe([
        'status' => 'unmonitored',
        'unmonitoredScope' => 'movie',
        'library' => ['service' => 'radarr', 'itemId' => 10, 'connectionId' => $this->radarr->id, 'seasonNumber' => null, 'onActiveConnection' => true],
    ]);
});

test('a monitored Radarr movie is in the library', function (): void {
    IndexedMovie::factory()->for($this->radarr, 'serviceConnection')->create(['radarr_id' => 10, 'tmdb_id' => 4935, 'monitored' => false]);
    Http::fake(['radarr.local:7878/api/v3/movie/10' => Http::response(['id' => 10, 'title' => 'Movie', 'monitored' => true])]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:3', 'movie', 4935)]);

    expect($result['anilist:3']['status'])->toBe('in_library')
        ->and($result['anilist:3']['unmonitoredScope'])->toBeNull();
});

test('a Radarr outage falls back to the indexed monitored flag', function (): void {
    Sleep::fake();
    IndexedMovie::factory()->for($this->radarr, 'serviceConnection')->create(['radarr_id' => 10, 'tmdb_id' => 4935, 'monitored' => false]);
    Http::fake(['radarr.local:7878/*' => Http::failedConnection()]);

    $result = (new SeasonLibraryStatus)->resolve([seasonLibraryRow('anilist:3', 'movie', 4935)]);

    expect($result['anilist:3']['status'])->toBe('unmonitored')
        ->and($result['anilist:3']['unmonitoredScope'])->toBe('movie');
});

test('unmapped and unowned rows are absent from the result', function (): void {
    expect((new SeasonLibraryStatus)->resolve([
        seasonLibraryRow('anilist:4', 'tv', null),
        seasonLibraryRow('anilist:5', 'tv', 1500, 99999, 1),
    ]))->toBe([]);
    Http::assertNothingSent();
});
