<?php

declare(strict_types=1);

use App\Models\AnimeIdMap;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('mediamanager.anime.source', 'anilist');
    Http::preventStrayRequests();

    // Deterministic "current season" — Summer 2026.
    Date::setTestNow(Date::create(2026, 8, 15, 12));

    $this->connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test-api-key',
    ]);
});

afterEach(function (): void {
    Date::setTestNow();
});

/**
 * @return array<string, array<string, array<string, mixed[]>>>
 */
function anilistSeasonResponse(array $media = []): array
{
    return [
        'data' => [
            'Page' => [
                'pageInfo' => ['hasNextPage' => false],
                'media' => $media,
            ],
        ],
    ];
}

test('guests are redirected to login', function (): void {
    $this->get(route('media.anime.index'))->assertRedirect(route('login'));
});

test('viewers cannot access the anime index', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('media.anime.index'))->assertForbidden();
});

test('index redirects to dashboard when no active seerr connection exists', function (): void {
    $this->connection->update(['is_active' => false]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.anime.index'))
        ->assertRedirect(route('dashboard'));
});

test('index renders the Season component with filters and navigation', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.anime.index', ['year' => 2026, 'season' => 'summer', 'source' => 'anilist']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Anime/Season')
            ->where('filters.year', 2026)
            ->where('filters.season', 'summer')
            ->where('filters.source', 'anilist')
            ->where('navigation.current.label', 'Summer 2026')
            ->where('navigation.previous.season', 'spring')
            ->where('navigation.previous.year', 2026)
            ->where('navigation.next.season', 'fall')
            ->where('navigation.next.year', 2026)
        );
});

test('index defers entries mapped against the id map with an overlaid status', function (): void {
    $member = User::factory()->member()->create();

    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 154587,
        'tmdb_tv_id' => 1396,
        'tvdb_id' => 81189,
        'tmdb_season' => 1,
    ]);

    Http::fake([
        'graphql.anilist.co' => Http::response(anilistSeasonResponse([
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
        ])),
        'seerr.local:5055/api/v1/request*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/user*' => Http::response(['results' => []]),
    ]);

    $this->actingAs($member)
        ->get(route('media.anime.index', ['year' => 2026, 'season' => 'summer']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Anime/Season')
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('entries', 1)
                    ->where('entries.0.title', 'Test Show')
                    ->where('entries.0.key', 'anilist:154587')
                    ->where('entries.0.mapping.tmdbId', 1396)
                    ->where('entries.0.mapping.mapped', true)
                    ->where('entries.0.status', 'requestable');
            })
        );
});

test('index defers requesting users with an email-matched default', function (): void {
    $member = User::factory()->member()->create(['email' => 'me@example.com']);

    Http::fake([
        'graphql.anilist.co' => Http::response(anilistSeasonResponse()),
        'seerr.local:5055/api/v1/request*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/user*' => Http::response([
            'results' => [
                ['id' => 1, 'displayName' => 'Someone', 'email' => 'other@example.com'],
                ['id' => 2, 'displayName' => 'Me', 'email' => 'me@example.com'],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.anime.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('requestingUsers.users', 2)
                    ->where('requestingUsers.defaultId', 2);
            })
        );
});

test('request submits a tv createRequest with the given season and userId', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 1]),
    ]);

    $this->actingAs($member)
        ->from(route('media.anime.index'))
        ->post(route('media.anime.request'), [
            'tmdbId' => 1396,
            'mediaType' => 'tv',
            'tmdbSeason' => 3,
            'userId' => 9,
        ])
        ->assertRedirect(route('media.anime.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v1/request')
        && $request->data() === [
            'mediaType' => 'tv',
            'mediaId' => 1396,
            'seasons' => [3],
            'userId' => 9,
        ]);
});

test('request submits a movie createRequest without a seasons field', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 2]),
    ]);

    $this->actingAs($member)
        ->from(route('media.anime.index'))
        ->post(route('media.anime.request'), [
            'tmdbId' => 129,
            'mediaType' => 'movie',
        ])
        ->assertRedirect(route('media.anime.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && ($request->data()['mediaType'] ?? null) === 'movie'
        && ($request->data()['mediaId'] ?? null) === 129
        && ! array_key_exists('seasons', $request->data()));
});

test('request validates media type', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->from(route('media.anime.index'))
        ->post(route('media.anime.request'), ['tmdbId' => 1, 'mediaType' => 'bogus'])
        ->assertSessionHasErrors('mediaType');
});

test('findMatch returns up to three tv or movie candidates', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [
                ['id' => 1, 'mediaType' => 'tv', 'name' => 'Show One', 'firstAirDate' => '2020-01-01', 'posterPath' => '/a.jpg'],
                ['id' => 2, 'mediaType' => 'movie', 'title' => 'Movie Two', 'releaseDate' => '2019-05-05'],
                ['id' => 3, 'mediaType' => 'person', 'name' => 'Ignored Person'],
                ['id' => 4, 'mediaType' => 'tv', 'name' => 'Show Four'],
                ['id' => 5, 'mediaType' => 'movie', 'title' => 'Movie Five'],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->from(route('media.anime.index'))
        ->post(route('media.anime.find-match'), ['title' => 'test'])
        ->assertRedirect(route('media.anime.index'))
        // The app relocates redirect ->with() data into inertia.flash_data.
        ->assertSessionHas('inertia.flash_data.matchCandidates');

    $candidates = session('inertia.flash_data.matchCandidates');
    expect($candidates)->toHaveCount(3);
    expect($candidates[0])->toMatchArray(['tmdbId' => 1, 'mediaType' => 'tv', 'title' => 'Show One', 'year' => '2020']);
    // The person entry is filtered out.
    expect(collect($candidates)->pluck('mediaType')->all())->not->toContain('person');
});

test('confirmMatch persists a user_confirmed row then requests it', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 3]),
    ]);

    $this->actingAs($member)
        ->from(route('media.anime.index'))
        ->post(route('media.anime.confirm-match'), [
            'anilistId' => 12345,
            'tmdbId' => 1396,
            'format' => 'TV',
            'tmdbSeason' => 1,
        ])
        ->assertRedirect(route('media.anime.index'));

    $this->assertDatabaseHas('anime_id_maps', [
        'anilist_id' => 12345,
        'tmdb_tv_id' => 1396,
        'user_confirmed' => true,
    ]);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v1/request')
        && ($request->data()['mediaType'] ?? null) === 'tv'
        && ($request->data()['mediaId'] ?? null) === 1396);
});

test('confirmMatch fails when no anime id is supplied', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->from(route('media.anime.index'))
        ->post(route('media.anime.confirm-match'), [
            'tmdbId' => 1396,
            'format' => 'TV',
        ])
        ->assertRedirect(route('media.anime.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    $this->assertDatabaseCount('anime_id_maps', 0);
});
