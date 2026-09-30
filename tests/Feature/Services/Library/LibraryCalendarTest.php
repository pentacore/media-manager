<?php

declare(strict_types=1);

use App\Cache\Services\SonarrCache;
use App\Models\ServiceConnection;
use App\Services\Library\LibraryCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-09-15T12:00:00Z'));
    $this->start = CarbonImmutable::parse('2026-08-25T00:00:00Z');
    $this->end = CarbonImmutable::parse('2026-10-08T00:00:00Z');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function calendarEpisode(array $overrides = []): array
{
    return [
        'id' => 70, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 2, 'title' => 'Half Loop',
        'airDateUtc' => '2026-09-10T02:00:00Z', 'hasFile' => false, 'monitored' => true,
        'series' => ['title' => 'Severance', 'monitored' => true, 'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://img.test/p.jpg']]],
        ...$overrides,
    ];
}

test('it merges Sonarr episodes and Radarr movies in air order with their states', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake([
        'sonarr.local:8989/api/v3/calendar*' => Http::response([
            calendarEpisode(),
            calendarEpisode(['id' => 71, 'episodeNumber' => 3, 'airDateUtc' => '2026-09-05T02:00:00Z', 'hasFile' => true]),
            calendarEpisode(['id' => 72, 'episodeNumber' => 4, 'airDateUtc' => '2026-09-20T02:00:00Z']),
            calendarEpisode(['id' => 73, 'episodeNumber' => 5, 'airDateUtc' => '2026-09-01T02:00:00Z', 'series' => ['title' => 'Old Show', 'monitored' => false, 'images' => []]]),
        ]),
        'radarr.local:7878/api/v3/calendar*' => Http::response([
            ['id' => 10, 'title' => 'Dune', 'monitored' => true, 'hasFile' => false, 'digitalRelease' => '2026-09-30T00:00:00Z', 'inCinemas' => '2026-03-01T00:00:00Z', 'images' => []],
        ]),
    ]);

    $calendar = resolve(LibraryCalendar::class)->between($this->start, $this->end);

    expect(array_column($calendar['items'], 'key'))->toBe([
        sprintf('sonarr:%d:73', $sonarr->id),
        sprintf('sonarr:%d:71', $sonarr->id),
        sprintf('sonarr:%d:70', $sonarr->id),
        sprintf('sonarr:%d:72', $sonarr->id),
        sprintf('radarr:%d:10', $radarr->id),
    ])
        ->and(array_column($calendar['items'], 'state'))->toBe(['unmonitored', 'downloaded', 'missing', 'upcoming', 'upcoming'])
        ->and($calendar['items'][2])->toMatchArray([
            'service' => 'sonarr',
            'title' => 'Severance',
            'episode_title' => 'Half Loop',
            'code' => 'S01E02',
            'air_date_utc' => '2026-09-10T02:00:00Z',
            'instance' => null,
            'poster_url' => 'https://img.test/p.jpg',
            'library_url' => '/media/series/7',
            'series_id' => 7,
            'episode_id' => 70,
        ])
        ->and($calendar['items'][4])->toMatchArray(['code' => 'Movie', 'air_date_utc' => '2026-09-30T00:00:00Z', 'library_url' => '/media/movies/10', 'movie_id' => 10])
        ->and($calendar['failures'])->toBe([]);
});

test('an episode of an unmonitored series keeps its own monitored flag beside the combined state', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake([
        'sonarr.local:8989/api/v3/calendar*' => Http::response([
            calendarEpisode(['series' => ['title' => 'Old Show', 'monitored' => false, 'images' => []]]),
            calendarEpisode(['id' => 71, 'monitored' => false, 'airDateUtc' => '2026-09-11T02:00:00Z']),
        ]),
        'radarr.local:7878/api/v3/calendar*' => Http::response([
            ['id' => 10, 'title' => 'Dune', 'monitored' => true, 'hasFile' => false, 'digitalRelease' => '2026-09-30T00:00:00Z', 'images' => []],
        ]),
    ]);

    $items = resolve(LibraryCalendar::class)->between($this->start, $this->end)['items'];

    expect($items[0])->toMatchArray(['episode_id' => 70, 'state' => 'unmonitored', 'monitored' => false, 'episode_monitored' => true])
        ->and($items[1])->toMatchArray(['episode_id' => 71, 'state' => 'unmonitored', 'monitored' => false, 'episode_monitored' => false])
        ->and($items[2])->toMatchArray(['movie_id' => 10, 'monitored' => true, 'episode_monitored' => null]);
});

test('one unreachable service still returns the other and names the failure', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'name' => 'Movies box']);
    Http::fake([
        'sonarr.local:8989/api/v3/calendar*' => Http::response([calendarEpisode()]),
        'radarr.local:7878/api/v3/calendar*' => Http::response([], 503),
    ]);

    $calendar = resolve(LibraryCalendar::class)->between($this->start, $this->end);

    expect($calendar['items'])->toHaveCount(1)
        ->and($calendar['failures'])->toBe([['service' => 'Radarr', 'instance' => 'Movies box']]);
});

test('a failing service is asked once per instance, without the generic retry', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake([
        'sonarr.local:8989/api/v3/calendar*' => Http::response([], 503),
        'radarr.local:7878/api/v3/calendar*' => Http::response([], 503),
    ]);

    resolve(LibraryCalendar::class)->between($this->start, $this->end);

    Http::assertSentCount(2);
});

test('a second instance is labelled and never links to the primary instance library page', function (): void {
    $main = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'name' => 'Main']);
    $anime = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-anime.local:8989', 'name' => 'Anime']);
    Http::fake([
        'sonarr.local:8989/api/v3/calendar*' => Http::response([calendarEpisode()]),
        'sonarr-anime.local:8989/api/v3/calendar*' => Http::response([calendarEpisode(['id' => 90, 'airDateUtc' => '2026-09-11T02:00:00Z'])]),
    ]);

    $items = resolve(LibraryCalendar::class)->between($this->start, $this->end)['items'];

    expect($items[0])->toMatchArray(['key' => sprintf('sonarr:%d:70', $main->id), 'instance' => 'Main', 'library_url' => '/media/series/7'])
        ->and($items[1])->toMatchArray(['key' => sprintf('sonarr:%d:90', $anime->id), 'instance' => 'Anime', 'library_url' => null]);
});

test('a movie with no release date inside the range is left out', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake(['radarr.local:7878/api/v3/calendar*' => Http::response([
        ['id' => 11, 'title' => 'Elsewhere', 'monitored' => true, 'hasFile' => false, 'inCinemas' => '2027-01-01T00:00:00Z', 'images' => []],
    ])]);

    expect(resolve(LibraryCalendar::class)->between($this->start, $this->end)['items'])->toBe([]);
});

test('the range is cached until a Sonarr webhook busts the cache', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake(['sonarr.local:8989/api/v3/calendar*' => Http::response([calendarEpisode()])]);
    $libraryCalendar = resolve(LibraryCalendar::class);

    $libraryCalendar->between($this->start, $this->end);
    $libraryCalendar->between($this->start, $this->end);
    Http::assertSentCount(1);

    new SonarrCache($sonarr)->bustAll();
    $libraryCalendar->between($this->start, $this->end);
    Http::assertSentCount(2);
});
