<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

function discoverSeerr(): ServiceConnection
{
    return ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
}

/**
 * @param  array<string, mixed>  $extra
 */
function fakeDiscoverSeerr(array $extra = []): void
{
    // array_merge keeps each default's position (pattern order matters: the
    // first matching pattern wins) while letting $extra override its value.
    Http::fake(array_merge([
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [
            ['id' => 7, 'email' => 'viewer@example.com', 'displayName' => 'Viewer'],
            ['id' => 8, 'email' => 'member@example.com', 'displayName' => 'Member'],
        ]]),
        'seerr.local:5055/api/v1/discover/trending*' => Http::response(['results' => [['id' => 1, 'mediaType' => 'movie', 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'mediaInfo' => ['status' => 5]]]]),
        'seerr.local:5055/api/v1/discover/movies/upcoming*' => Http::response(['results' => [['id' => 2, 'mediaType' => 'movie', 'title' => 'Later Movie', 'releaseDate' => '2026-12-01']]]),
        'seerr.local:5055/api/v1/discover/tv/upcoming*' => Http::response(['results' => [['id' => 3, 'mediaType' => 'tv', 'name' => 'Soon Show', 'firstAirDate' => '2026-10-01']]]),
        'seerr.local:5055/api/v1/discover/movies' => Http::response(['results' => [['id' => 4, 'mediaType' => 'movie', 'title' => 'Popular Movie']]]),
        'seerr.local:5055/api/v1/discover/tv' => Http::response(['results' => [['id' => 5, 'mediaType' => 'tv', 'name' => 'Popular Show']]]),
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 99, 'status' => 1], 201),
    ], $extra));
}

test('discover without a Seerr connection renders the unavailable state and no rows', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('media.discover.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Discover/Index')
            ->where('seerr.connected', false)
            ->missing('trending')
            ->missing('requesting'));
});

test('discover loads each row in its own deferred group with mapped statuses', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr();

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->get(route('media.discover.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('seerr.connected', true)
            ->missing('trending')
            ->loadDeferredProps('trending', fn ($reload) => $reload
                ->where('trending.error', null)
                ->where('trending.results.0.title', 'Dune')
                ->where('trending.results.0.status', 'available')
                ->missing('upcoming'))
            ->loadDeferredProps('upcoming', fn ($reload) => $reload
                ->where('upcoming.results.0.title', 'Soon Show')
                ->where('upcoming.results.1.title', 'Later Movie'))
            ->loadDeferredProps('popularMovies', fn ($reload) => $reload->where('popularMovies.results.0.title', 'Popular Movie'))
            ->loadDeferredProps('popularTv', fn ($reload) => $reload->where('popularTv.results.0.title', 'Popular Show'))
            ->loadDeferredProps('requesting', fn ($reload) => $reload
                ->where('requesting.canChooseUser', false)
                ->where('requesting.userId', 7)));
});

test('a row whose Seerr call fails reports an error instead of an empty list', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr(['seerr.local:5055/api/v1/discover/trending*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())
        ->get(route('media.discover.index'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('trending', fn ($reload) => $reload
                ->where('trending.results', [])
                ->where('trending.error', 'Seerr is unreachable right now.')));
});

test('title detail returns the sheet payload with season states', function (): void {
    discoverSeerr();
    Http::fake(['seerr.local:5055/api/v1/tv/95396' => Http::response([
        'id' => 95396, 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'numberOfSeasons' => 2,
        'seasons' => [['seasonNumber' => 1, 'name' => 'Season 1', 'episodeCount' => 9], ['seasonNumber' => 2, 'name' => 'Season 2', 'episodeCount' => 10]],
        'mediaInfo' => ['status' => 4, 'seasons' => [['seasonNumber' => 1, 'status' => 5]]],
    ])]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('media.discover.title', ['mediaType' => 'tv', 'tmdbId' => 95396]))
        ->assertOk()
        ->assertJsonPath('title', 'Severance')
        ->assertJsonPath('status', 'partially_available')
        ->assertJsonPath('seasons.0.requestable', false)
        ->assertJsonPath('seasons.1.requestable', true);
});

test('title detail answers 502 when Seerr is down', function (): void {
    discoverSeerr();
    Http::fake(['seerr.local:5055/api/v1/movie/1' => fn () => throw new ConnectionException('down')]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('media.discover.title', ['mediaType' => 'movie', 'tmdbId' => 1]))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Seerr is unreachable right now.');
});

test('a viewer requests as their resolved Seerr user even when the body names another user', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr();

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->post(route('media.discover.request'), ['tmdbId' => 95396, 'mediaType' => 'tv', 'seasons' => [2], 'userId' => 8])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Request submitted.')
        ->assertSessionHas('inertia.flash_data.requestOutcome.ok', true);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/request')
        && $request['userId'] === 7
        && $request['seasons'] === [2]
        && $request['mediaType'] === 'tv');
});

test('a viewer without a Seerr match is refused before anything is sent', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr();

    $this->actingAs(User::factory()->create(['email' => 'nobody@example.com']))
        ->post(route('media.discover.request'), ['tmdbId' => 1, 'mediaType' => 'movie'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'No Seerr account is linked to you — ask an admin.')
        ->assertSessionHas('inertia.flash_data.requestOutcome.ok', false);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('a member requests for the Seerr user they chose', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr();

    $this->actingAs(User::factory()->member()->create(['email' => 'member@example.com']))
        ->post(route('media.discover.request'), ['tmdbId' => 1, 'mediaType' => 'movie', 'userId' => 7])
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['userId'] === 7 && ! isset($request['seasons']));
});

test('a member choosing a user Seerr does not know is refused before anything is sent', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr();

    $this->actingAs(User::factory()->member()->create(['email' => 'member@example.com']))
        ->post(route('media.discover.request'), ['tmdbId' => 1, 'mediaType' => 'movie', 'userId' => 999])
        ->assertSessionHas('inertia.flash_data.toast.message', 'No Seerr account is linked to you — ask an admin.')
        ->assertSessionHas('inertia.flash_data.requestOutcome.ok', false);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('Seerr errors become clear toasts', function (int $status, array $body, string $message): void {
    discoverSeerr();
    fakeDiscoverSeerr(['seerr.local:5055/api/v1/request' => Http::response($body, $status)]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->post(route('media.discover.request'), ['tmdbId' => 1, 'mediaType' => 'movie'])
        ->assertSessionHas('inertia.flash_data.toast.message', $message)
        ->assertSessionHas('inertia.flash_data.requestOutcome.ok', false);
})->with([
    'quota' => [403, ['message' => 'Movie Quota exceeded.'], 'Seerr quota reached — try again later.'],
    'permission' => [403, ['message' => 'You do not have permission to request 4K movies.'], 'Seerr refused the request: You do not have permission to request 4K movies.'],
    'duplicate' => [409, ['message' => 'Request for this media already exists.'], 'This title has already been requested.'],
    'server error' => [500, [], 'Seerr is unreachable right now.'],
]);

test('a lost Seerr response tells the user to check before retrying', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr(['seerr.local:5055/api/v1/request' => fn () => throw new ConnectionException('reset')]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->post(route('media.discover.request'), ['tmdbId' => 1, 'mediaType' => 'movie'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Seerr did not answer — check My requests before trying again.');
});

test('a request with nothing left to request says so', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr(['seerr.local:5055/api/v1/request' => Http::response(['message' => 'No seasons available to request'], 202)]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->post(route('media.discover.request'), ['tmdbId' => 95396, 'mediaType' => 'tv', 'seasons' => [1]])
        ->assertSessionHas('inertia.flash_data.toast.type', 'info')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Everything in this title is already requested or available.');
});

test('requests are rate limited per user', function (): void {
    discoverSeerr();
    fakeDiscoverSeerr();
    $viewer = User::factory()->create(['email' => 'viewer@example.com']);

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($viewer)->post(route('media.discover.request'), ['tmdbId' => $attempt, 'mediaType' => 'movie'])->assertRedirect();
    }

    $this->actingAs($viewer)->post(route('media.discover.request'), ['tmdbId' => 11, 'mediaType' => 'movie'])->assertTooManyRequests();
});

test('the request is validated', function (): void {
    discoverSeerr();

    $this->actingAs(User::factory()->create())
        ->post(route('media.discover.request'), ['tmdbId' => 'x', 'mediaType' => 'person'])
        ->assertSessionHasErrors(['tmdbId', 'mediaType']);
});
