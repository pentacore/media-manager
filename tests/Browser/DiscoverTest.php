<?php

declare(strict_types=1);

use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeDiscoverPageSeerr(): void
{
    Http::fake([
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [
            ['id' => 7, 'email' => 'someone@example.com', 'displayName' => 'Viewer', 'jellyfinUserId' => 'a1b2c3d4e5f647118899aabbccddeeff'],
        ]]),
        'seerr.local:5055/api/v1/discover/trending*' => Http::response(['results' => [
            ['id' => 95396, 'mediaType' => 'tv', 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'posterPath' => '/severance.jpg', 'mediaInfo' => ['status' => 4]],
        ]]),
        'seerr.local:5055/api/v1/discover/movies/upcoming*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/discover/tv/upcoming*' => Http::response(['results' => []]),
        // posterPath keeps each card an <img> instead of Poster's text-hint
        // fallback — without it the hint (the title, lowercased) and the
        // card's own title span both carry the same text, so Playwright's
        // strict-mode text locator resolves two matches instead of one.
        'seerr.local:5055/api/v1/discover/movies' => Http::response(['results' => [['id' => 438631, 'mediaType' => 'movie', 'title' => 'Dune', 'posterPath' => '/dune.jpg']]]),
        'seerr.local:5055/api/v1/discover/tv' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/tv/95396' => Http::response([
            'id' => 95396, 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'overview' => 'Work-life balance.',
            'posterPath' => '/severance.jpg', 'voteAverage' => 8.4, 'numberOfSeasons' => 2, 'episodeRunTime' => [55],
            'seasons' => [['seasonNumber' => 1, 'name' => 'Season 1', 'episodeCount' => 9], ['seasonNumber' => 2, 'name' => 'Season 2', 'episodeCount' => 10]],
            'mediaInfo' => ['status' => 4, 'seasons' => [['seasonNumber' => 1, 'status' => 5]]],
        ]),
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 99, 'status' => 1], 201),
    ]);
}

test('a viewer browses discover rows, opens a show and requests the missing season as themselves', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    fakeDiscoverPageSeerr();
    $viewer = User::factory()->create(['email' => 'viewer@example.com']);
    EmbyUserLink::factory()->for($viewer)->create(['emby_user_id' => 'A1B2C3D4-E5F6-4711-8899-AABBCCDDEEFF']);

    $this->actingAs($viewer);

    visit(route('media.discover.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-discover-row="trending"]', 'Severance')
        ->assertSeeIn('[data-discover-row="popularMovies"]', 'Dune')
        ->click('[data-title-card="tv-95396"]')
        ->assertSeeIn('[data-title-sheet]', 'Work-life balance.')
        ->assertSeeIn('[data-title-sheet]', 'Season 2')
        ->assertScript('document.querySelector(\'[data-season-option="1"] button\').disabled', true)
        ->click('[data-request-submit]')
        ->assertSee('Request submitted.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/request')
        && $request['userId'] === 7
        && $request['seasons'] === [2]);
});

test('a viewer with no Seerr account sees why instead of a request button', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    fakeDiscoverPageSeerr();

    $this->actingAs(User::factory()->create(['email' => 'stranger@example.com']));

    visit(route('media.discover.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-title-card="tv-95396"]')
        ->assertSeeIn('[data-no-seerr-account]', 'No Seerr account is linked to you — ask an admin.')
        ->assertCount('[data-request-submit]', 0);
});

// The `requesting` prop is page-level and shared across every title sheet;
// TitleDetailSheet used to reset the chosen user back to the context default
// on every reload of that prop, silently discarding a chooser's manual pick.
// A request submission redirects back() with preserveState (the sheet stays
// mounted, `requesting` refetches) — exactly the reload that must not reset
// a pick the chooser already made.
test("a chooser's manual user pick survives a requesting-context reload after submitting", function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [
            ['id' => 7, 'email' => 'chooser@example.com', 'displayName' => 'Chooser'],
            ['id' => 8, 'email' => 'other@example.com', 'displayName' => 'Other'],
        ]]),
        'seerr.local:5055/api/v1/discover/trending*' => Http::response(['results' => [
            ['id' => 95396, 'mediaType' => 'tv', 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'posterPath' => '/severance.jpg', 'mediaInfo' => ['status' => 4]],
        ]]),
        'seerr.local:5055/api/v1/discover/movies/upcoming*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/discover/tv/upcoming*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/discover/movies' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/discover/tv' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/tv/95396' => Http::response([
            'id' => 95396, 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'overview' => 'Work-life balance.',
            'posterPath' => '/severance.jpg', 'voteAverage' => 8.4, 'numberOfSeasons' => 2, 'episodeRunTime' => [55],
            'seasons' => [['seasonNumber' => 1, 'name' => 'Season 1', 'episodeCount' => 9], ['seasonNumber' => 2, 'name' => 'Season 2', 'episodeCount' => 10]],
            'mediaInfo' => ['status' => 4, 'seasons' => [['seasonNumber' => 1, 'status' => 5]]],
        ]),
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 99, 'status' => 1], 201),
    ]);

    $this->actingAs(User::factory()->member()->create(['email' => 'chooser@example.com']));

    $webpage = visit(route('media.discover.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-title-card="tv-95396"]')
        ->assertSeeIn('[data-title-sheet]', 'Season 2');

    $webpage->click('[data-slot="select-trigger"]')
        ->click('[data-slot="select-item"]:has-text("Other")')
        ->click('[data-request-submit]')
        ->assertSee('Request submitted.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/request')
        && $request['userId'] === 8);

    $webpage->assertSeeIn('[data-slot="select-trigger"]', 'Other');
});

test('discover explains itself when Seerr is not connected', function (): void {
    $this->actingAs(User::factory()->create());

    visit(route('media.discover.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-discover-unavailable]', 'Discover needs Seerr');
});

// SeerrUserResolver::pickerOptions() no longer falls back to an arbitrary
// Seerr user, so a chooser (member/admin) with no own Emby/email match gets
// `canChooseUser: true, userId: null` — the Request button must stay
// disabled until they actually pick someone (mirrors Anime/Season.vue's
// resolvedUserId guard), never silently file the request under whichever
// Seerr account owns the API key.
test('a chooser with no Seerr match must pick a user before Request enables', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    fakeDiscoverPageSeerr();

    $this->actingAs(User::factory()->member()->create(['email' => 'chooser@example.com']));

    $webpage = visit(route('media.discover.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-title-card="tv-95396"]')
        ->assertSeeIn('[data-title-sheet]', 'Season 2')
        ->assertDisabled('[data-request-submit]')
        ->assertSeeIn('[data-no-user-chosen]', 'Choose which Seerr user to request as.');

    $webpage->click('[data-slot="select-trigger"]')
        ->click('[data-slot="select-item"]:has-text("Viewer")')
        ->assertDontSee('Choose which Seerr user to request as.')
        ->assertEnabled('[data-request-submit]')
        ->click('[data-request-submit]')
        ->assertSee('Request submitted.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/request')
        && $request['userId'] === 7);
});
