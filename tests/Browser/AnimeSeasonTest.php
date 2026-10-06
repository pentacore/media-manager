<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\AnimeIdMap;
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

function animeSeasonOwnedFixture(bool $seasonMonitored): ServiceConnection
{
    config()->set('mediamanager.anime.source', 'anilist');
    Date::setTestNow(Date::create(2026, 8, 15, 12));
    Queue::fake();

    ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test-api-key',
    ]);

    $sonarr = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 154587,
        'tmdb_tv_id' => 1396,
        'tvdb_id' => 81189,
        'tmdb_season' => 1,
        'tvdb_season' => 2,
    ]);

    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create([
        'sonarr_id' => 7,
        'tvdb_id' => 81189,
        'monitored' => true,
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
        'seerr.local:5055/api/v1/user*' => Http::response(['results' => []]),
        'sonarr.local:8989/api/v3/series/7' => Http::response([
            'id' => 7,
            'title' => 'Test Show',
            'monitored' => true,
            'seasons' => [['seasonNumber' => 2, 'monitored' => $seasonMonitored]],
        ]),
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 1]),
    ]);

    return $sonarr;
}

test('an owned entry with an unmonitored season shows the pill, opens the series and monitors the season', function (): void {
    $sonarr = animeSeasonOwnedFixture(seasonMonitored: false);
    $this->seed(ActionTypeConfigSeeder::class);
    $this->actingAs(User::factory()->member()->create(['email' => 'nobody@example.com']));

    visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-anime-status]', 'Season unmonitored')
        ->assertAttributeContains('[data-anime-open]', 'href', '/media/series/7')
        ->assertAttributeContains('[data-anime-external]', 'href', 'https://anilist.co/anime/154587')
        ->click('[data-anime-monitor]')
        ->assertSee('Monitoring updated.')
        ->assertDisabled('[data-anime-monitor]');

    expect(ActionRequest::query()->where('type', 'monitor_season')->sole()->payload)
        ->toEqual(['series_id' => 7, 'season_number' => 2, 'service_connection_id' => $sonarr->id]);
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
