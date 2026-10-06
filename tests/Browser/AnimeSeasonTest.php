<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\AnimeIdMap;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the request button stays disabled until a Seerr user is picked, then submits as that user', function (): void {
    config()->set('mediamanager.anime.source', 'anilist');
    Date::setTestNow(Date::create(2026, 8, 15, 12));
    Queue::fake();

    ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test-api-key',
    ]);

    // A single mapped, requestable entry.
    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 154587,
        'tmdb_tv_id' => 1396,
        'tvdb_id' => 81189,
        'tmdb_season' => 1,
    ]);

    Http::fake([
        'graphql.anilist.co' => Http::response([
            'data' => [
                'Page' => [
                    'pageInfo' => ['hasNextPage' => false],
                    'media' => [
                        [
                            'id' => 154587,
                            'idMal' => 52991,
                            'format' => 'TV',
                            'status' => 'RELEASING',
                            'episodes' => 12,
                            'popularity' => 5000,
                            'averageScore' => 88,
                            'title' => ['romaji' => 'Test', 'english' => 'Test Show'],
                            'startDate' => ['year' => 2026, 'month' => 7, 'day' => 1],
                            'coverImage' => ['large' => 'https://img/test.jpg'],
                        ],
                    ],
                ],
            ],
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/user*' => Http::response(['results' => [
            ['id' => 4, 'displayName' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 5, 'displayName' => 'Bob', 'email' => 'bob@example.com'],
        ]]),
    ]);

    // No email match against either faked Seerr user, so pickerOptions()
    // returns no default — the picker starts on its "Select user" placeholder.
    $member = User::factory()->member()->create(['email' => 'nobody@example.com']);
    $this->actingAs($member);

    $webpage = visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-anime-user-select]', 'Select user')
        ->assertSee('Test Show')
        ->assertDisabled('[data-anime-request]');

    $webpage->click('[data-anime-user-select]')
        ->click('[data-slot="select-item"]:has-text("Alice")')
        ->assertEnabled('[data-anime-request]')
        ->click('[data-anime-request]')
        ->assertSee('Request submitted.');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v1/request')
        && ($request->data()['userId'] ?? null) === 4);
});

test('a picker outage shows an unreachable message instead of an empty user select', function (): void {
    config()->set('mediamanager.anime.source', 'anilist');
    Date::setTestNow(Date::create(2026, 8, 15, 12));
    Queue::fake();

    ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test-api-key',
    ]);

    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 154587,
        'tmdb_tv_id' => 1396,
        'tvdb_id' => 81189,
        'tmdb_season' => 1,
    ]);

    Http::fake([
        'graphql.anilist.co' => Http::response([
            'data' => [
                'Page' => [
                    'pageInfo' => ['hasNextPage' => false],
                    'media' => [
                        [
                            'id' => 154587,
                            'idMal' => 52991,
                            'format' => 'TV',
                            'status' => 'RELEASING',
                            'episodes' => 12,
                            'popularity' => 5000,
                            'averageScore' => 88,
                            'title' => ['romaji' => 'Test', 'english' => 'Test Show'],
                            'startDate' => ['year' => 2026, 'month' => 7, 'day' => 1],
                            'coverImage' => ['large' => 'https://img/test.jpg'],
                        ],
                    ],
                ],
            ],
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/user*' => Http::response([], 503),
    ]);

    $member = User::factory()->member()->create(['email' => 'nobody@example.com']);
    $this->actingAs($member);

    visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertSee('Test Show')
        ->assertSeeIn('[data-seerr-picker-unreachable]', 'Seerr is unreachable right now.');
});

/**
 * Http fakes for one AniList seasonal entry plus an empty Seerr.
 *
 * @return array<string, mixed>
 */
function animeSeasonAniListFakes(string $format): array
{
    return [
        'graphql.anilist.co' => Http::response([
            'data' => [
                'Page' => [
                    'pageInfo' => ['hasNextPage' => false],
                    'media' => [
                        [
                            'id' => 154587,
                            'idMal' => 52991,
                            'format' => $format,
                            'status' => 'RELEASING',
                            'episodes' => 12,
                            'popularity' => 5000,
                            'averageScore' => 88,
                            'title' => ['romaji' => 'Test', 'english' => 'Test Show'],
                            'startDate' => ['year' => 2026, 'month' => 7, 'day' => 1],
                            'coverImage' => ['large' => 'https://img/test.jpg'],
                        ],
                    ],
                ],
            ],
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/user*' => Http::response(['results' => []]),
    ];
}

function animeSeasonFixtureBasics(): void
{
    config()->set('mediamanager.anime.source', 'anilist');
    Date::setTestNow(Date::create(2026, 8, 15, 12));
    Queue::fake();

    ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test-api-key',
    ]);
}

function animeSeasonOwnedFixture(bool $seasonMonitored, bool $seriesMonitored = true, ?int $tvdbSeason = 2, bool $activeConnection = true): ServiceConnection
{
    animeSeasonFixtureBasics();

    $serviceConnectionFactory = ServiceConnection::factory()->sonarr();
    $sonarr = ($activeConnection ? $serviceConnectionFactory : $serviceConnectionFactory->inactive())->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 154587,
        'tmdb_tv_id' => 1396,
        'tvdb_id' => 81189,
        'tmdb_season' => 1,
        'tvdb_season' => $tvdbSeason,
    ]);

    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create([
        'sonarr_id' => 7,
        'tvdb_id' => 81189,
        'monitored' => $seriesMonitored,
    ]);

    Http::fake([
        ...animeSeasonAniListFakes('TV'),
        'sonarr.local:8989/api/v3/series/7' => Http::response([
            'id' => 7,
            'title' => 'Test Show',
            'monitored' => $seriesMonitored,
            'seasons' => [['seasonNumber' => 2, 'monitored' => $seasonMonitored]],
        ]),
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 1]),
    ]);

    return $sonarr;
}

function animeSeasonOwnedMovieFixture(): ServiceConnection
{
    animeSeasonFixtureBasics();

    $radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);

    AnimeIdMap::factory()->movie()->create([
        'anilist_id' => 154587,
        'tmdb_movie_id' => 129,
    ]);

    IndexedMovie::factory()->for($radarr, 'serviceConnection')->create([
        'radarr_id' => 10,
        'tmdb_id' => 129,
        'monitored' => true,
    ]);

    Http::fake([
        ...animeSeasonAniListFakes('MOVIE'),
        'radarr.local:7878/api/v3/movie/10' => Http::response(['id' => 10, 'title' => 'Test Show', 'monitored' => false]),
        'radarr.local:7878/api/v3/command' => Http::response(['id' => 1]),
    ]);

    return $radarr;
}

test('an owned entry with an unmonitored season shows the pill, opens the series and monitors the season', function (): void {
    $serviceConnection = animeSeasonOwnedFixture(seasonMonitored: false);
    $this->seed(ActionTypeConfigSeeder::class);
    $this->actingAs(User::factory()->member()->create(['email' => 'nobody@example.com']));

    visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-anime-status]', 'Season unmonitored')
        ->assertAttributeContains('[data-anime-open]', 'href', '/media/series/7')
        ->assertAttributeContains('[data-anime-external]', 'href', 'https://anilist.co/anime/154587')
        ->assertAttribute('[data-anime-external]', 'aria-label', 'Open on AniList')
        ->click('[data-anime-monitor]')
        ->assertSee('Monitoring updated.')
        ->assertDisabled('[data-anime-monitor]')
        ->assertSeeIn('[data-anime-monitor]', 'Monitor requested');

    expect(ActionRequest::query()->where('type', 'monitor_season')->sole()->payload)
        ->toEqual(['series_id' => 7, 'season_number' => 2, 'service_connection_id' => $serviceConnection->id]);
});

test('a fully monitored owned entry offers Open series but no Monitor button', function (): void {
    animeSeasonOwnedFixture(seasonMonitored: true);
    $this->actingAs(User::factory()->member()->create(['email' => 'nobody@example.com']));

    visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-anime-status]', 'In library')
        ->assertPresent('[data-anime-open]')
        ->assertMissing('[data-anime-monitor]');
});

test('an owned anime movie Radarr does not monitor shows the pill, opens the movie and monitors it', function (): void {
    $serviceConnection = animeSeasonOwnedMovieFixture();
    $this->seed(ActionTypeConfigSeeder::class);
    $this->actingAs(User::factory()->member()->create(['email' => 'nobody@example.com']));

    visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-anime-status]', 'Unmonitored')
        ->assertSeeIn('[data-anime-open]', 'Open movie')
        ->assertAttributeContains('[data-anime-open]', 'href', '/media/movies/10')
        ->click('[data-anime-monitor]')
        ->assertSee('Monitoring updated.');

    expect(ActionRequest::query()->where('type', 'monitor_movie')->sole()->payload)
        ->toEqual(['movie_id' => 10, 'monitored' => true, 'service_connection_id' => $serviceConnection->id]);
});

test('an unmonitored series with an unknown season is monitored at series level', function (): void {
    $serviceConnection = animeSeasonOwnedFixture(seasonMonitored: true, seriesMonitored: false, tvdbSeason: null);
    $this->seed(ActionTypeConfigSeeder::class);
    $this->actingAs(User::factory()->member()->create(['email' => 'nobody@example.com']));

    visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-anime-status]', 'Unmonitored')
        ->click('[data-anime-monitor]')
        ->assertSee('Monitoring updated.');

    expect(ActionRequest::query()->where('type', 'monitor_series')->sole()->payload)
        ->toEqual(['series_id' => 7, 'monitored' => true, 'service_connection_id' => $serviceConnection->id])
        ->and(ActionRequest::query()->where('type', 'monitor_season')->exists())->toBeFalse();
});

test('a refused Monitor leaves the button enabled so it can be retried', function (): void {
    animeSeasonOwnedFixture(seasonMonitored: false);
    ActionTypeConfig::factory()->create(['type' => 'monitor_season', 'is_enabled' => false]);
    $this->actingAs(User::factory()->member()->create(['email' => 'nobody@example.com']));

    visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-anime-monitor]')
        ->assertSee('This action is disabled in Action Rules.')
        ->assertEnabled('[data-anime-monitor]')
        ->assertSeeIn('[data-anime-monitor]', 'Monitor');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('an owned entry on an inactive connection shows its library status instead of actions', function (): void {
    animeSeasonOwnedFixture(seasonMonitored: true, seriesMonitored: false, activeConnection: false);
    $this->actingAs(User::factory()->member()->create(['email' => 'nobody@example.com']));

    visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-anime-monitor]')
        ->assertMissing('[data-anime-open]')
        ->assertSeeIn('[data-anime-library-status]', 'Unmonitored');
});
