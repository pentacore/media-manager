<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function browsePhoneFakeSeerrRequests(): void
{
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 1, 'pending' => 1, 'approved' => 0, 'declined' => 0, 'movie' => 0, 'tv' => 1]),
        'seerr.local:5055/api/v1/request/1/approve' => Http::response(['id' => 1, 'status' => 2]),
        'seerr.local:5055/api/v1/request/1/retry' => Http::response(['id' => 1, 'status' => 2]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1], 'results' => [
            ['id' => 1, 'status' => 1, 'type' => 'tv', 'media' => ['mediaType' => 'tv', 'tmdbId' => 95396, 'tvdbId' => 371980, 'status' => 3],
                'requestedBy' => ['displayName' => 'Example Requester With A Long Name', 'email' => 'requester@example.com'], 'createdAt' => '2026-10-01T00:00:00Z', 'seasons' => [['seasonNumber' => 1]]],
        ]]),
        'seerr.local:5055/api/v1/tv/*' => Http::response(['id' => 95396, 'name' => 'The Extraordinarily Long Example Series Title Of Doom', 'posterPath' => '/example.jpg', 'firstAirDate' => '2022-02-17', 'seasons' => [['seasonNumber' => 1, 'name' => 'Season 1', 'episodeCount' => 9]]]),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => []]),
        'seerr.local:5055/*' => Http::response([]),
    ]);
}

function browsePhoneInsideCardScript(string $card): string
{
    $encoded = json_encode($card, JSON_THROW_ON_ERROR);

    return "(() => { const card = document.querySelector({$encoded}).getBoundingClientRect(); return [...document.querySelector({$encoded}).querySelectorAll('a[href], button')].every((control) => { const rect = control.getBoundingClientRect(); return rect.left >= card.left - 1 && rect.right <= card.right + 1; }); })()";
}

test("an admin's request card keeps Approve, Decline, edit and delete on the card on a phone", function (): void {
    browsePhoneFakeSeerrRequests();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-request-card=\"1\"]")'));

    $webpage->assertScript(browsePhoneInsideCardScript('[data-request-card="1"]'), true)
        ->assertScript(phoneOverflowScript('main'), '')
        ->click('[data-request-card="1"] [data-request-delete]')
        ->assertVisible('[data-confirm-dialog]')
        ->click('[data-confirm-cancel]');
    $webpage->script(confirmDialogGoneScript());

    $webpage->click('[data-request-card="1"] [data-request-decline]')
        ->assertVisible('[data-confirm-dialog]')
        ->click('[data-confirm-cancel]');
    $webpage->script(confirmDialogGoneScript());

    $webpage->click('[data-request-card="1"] [data-request-edit]')
        ->assertVisible('[role="dialog"]');
    $webpage->keys('[role="dialog"]', 'Escape');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[role=\"dialog\"]")'));

    $webpage->click('[data-request-card="1"] [data-request-retry]')
        ->assertSee('Request retry triggered.');

    $webpage->click('[data-request-card="1"] [data-request-approve]')
        ->assertSee('Request approved.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/request/1/retry'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/request/1/approve'));
});

test("a member's request card has no edit, retry or delete on a phone", function (): void {
    browsePhoneFakeSeerrRequests();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-request-card=\\"1\\"]")'));

    $webpage->assertVisible('[data-request-card="1"] [data-request-approve]')
        ->assertCount('[data-request-card="1"] [data-request-edit]', 0)
        ->assertCount('[data-request-card="1"] [data-request-retry]', 0)
        ->assertCount('[data-request-card="1"] [data-request-delete]', 0)
        ->assertScript(phoneOverflowScript('main'), '')
        ->assertNoSmoke();
});

test("a TV title's sheet keeps its season picker and the requester select on a phone", function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response(['results' => [
            ['id' => 95396, 'mediaType' => 'tv', 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'posterPath' => '/poster.jpg'],
        ]]),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [
            ['id' => 7, 'displayName' => 'Example Requester With A Long Name', 'email' => 'requester@example.com'],
        ]]),
        'seerr.local:5055/api/v1/tv/95396' => Http::response(['id' => 95396, 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'overview' => 'Work and life, split.', 'seasons' => [
            ['seasonNumber' => 1, 'name' => 'Season 1', 'episodeCount' => 9],
            ['seasonNumber' => 2, 'name' => 'Season 2', 'episodeCount' => 10],
        ]]),
        'seerr.local:5055/*' => Http::response([]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.search.index', ['q' => 'severance'], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-search-title="tv-95396"]')
        ->assertSeeIn('[data-title-sheet]', 'Work and life, split.')
        ->assertVisible('[data-title-sheet] [data-season-option="1"]')
        ->assertVisible('[data-title-sheet] [data-season-option="2"]')
        ->assertScript(phoneOverflowScript('[data-title-sheet]'), '');
    // The season picker toggles from a tap (whatever it starts as).
    $webpage->script('(() => { window.__mmSeasonState = document.querySelector("[data-title-sheet] [data-season-option=\\"2\\"] [role=\\"checkbox\\"]").getAttribute("data-state"); })()');
    $webpage->click('[data-title-sheet] [data-season-option="2"] [role="checkbox"]')
        ->assertScript('document.querySelector("[data-title-sheet] [data-season-option=\\"2\\"] [role=\\"checkbox\\"]").getAttribute("data-state") !== window.__mmSeasonState', true)
        ->assertScript('(() => { const trigger = document.querySelector("[data-title-sheet] [data-slot=\\"select-trigger\\"]").getBoundingClientRect(); return trigger.left >= 0 && trigger.right <= window.innerWidth; })()', true)
        ->click('[data-title-sheet] [data-slot="select-trigger"]')
        ->assertScript('(() => { const list = document.querySelector("[role=\\"listbox\\"]").getBoundingClientRect(); return list.left >= 0 && list.right <= window.innerWidth; })()', true)
        ->assertNoSmoke();
});

test('a viewer finds a title in Search and requests it from the full-width sheet on a phone', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response(['results' => [
            ['id' => 438631, 'mediaType' => 'movie', 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'posterPath' => '/poster.jpg'],
        ]]),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'viewer@example.com']]]),
        'seerr.local:5055/api/v1/movie/438631' => Http::response(['id' => 438631, 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'runtime' => 155, 'overview' => 'Spice.']),
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 5, 'status' => 1], 201),
    ]);
    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']));

    $webpage = visit(route('media.search.index', ['q' => 'dune'], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript('[...document.querySelectorAll("main form kbd")].every((hint) => getComputedStyle(hint).display === "none")', true)
        ->click('[data-search-title="movie-438631"]')
        ->assertSeeIn('[data-title-sheet]', 'Spice.')
        ->assertScript('Math.round(document.querySelector("[data-title-sheet]").getBoundingClientRect().width) === window.innerWidth', true)
        ->assertVisible('[data-title-sheet] [data-sheet-close]')
        ->assertScript(phoneOverflowScript('[data-title-sheet]'), '')
        ->click('[data-request-submit]')
        ->assertSee('Request submitted.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/request') && $request['mediaType'] === 'movie' && $request['mediaId'] === 438631);
});

test('discover cards are wide enough to tap and snap as the row scrolls on a phone', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/discover/trending*' => Http::response(['results' => [
            ['id' => 95396, 'mediaType' => 'tv', 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'posterPath' => '/severance.jpg'],
            ['id' => 438631, 'mediaType' => 'movie', 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'posterPath' => '/dune.jpg'],
            ['id' => 438632, 'mediaType' => 'movie', 'title' => 'Example Movie', 'releaseDate' => '2021-10-22', 'posterPath' => '/x.jpg'],
        ]]),
        'seerr.local:5055/api/v1/discover/*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => []]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.discover.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(confirmDialogWaitUntilScript('document.querySelectorAll("[data-discover-row=\"trending\"] [data-title-card]").length === 3'));

    $webpage->assertScript('[...document.querySelectorAll("[data-title-card]")].every((card) => card.getBoundingClientRect().width >= 140)', true)
        ->assertScript('getComputedStyle(document.querySelector("[data-discover-row=\"trending\"] [data-title-card]").parentElement).scrollSnapType', 'x mandatory')
        ->assertScript(phoneOverflowScript('main'), '');
});

test('my request rows fit a phone and Cancel is reachable', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'viewer@example.com']]]),
        'seerr.local:5055/api/v1/request/41' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(null, 204)
            : Http::response(['id' => 41, 'status' => 1, 'requestedBy' => ['id' => 7]]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1, 'results' => 1, 'pageSize' => 20], 'results' => [
            ['id' => 41, 'status' => 1, 'type' => 'movie', 'media' => ['tmdbId' => 1, 'mediaType' => 'movie', 'status' => 2], 'requestedBy' => ['id' => 7]],
        ]]),
        'seerr.local:5055/api/v1/movie/1' => Http::response(['id' => 1, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'posterPath' => '/example.jpg']),
    ]);
    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']));

    $webpage = visit(route('media.requests.mine', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-my-request=\"41\"]")'));

    $webpage->assertScript(phoneOverflowScript('main'), '')
        ->click('[data-cancel-request="41"]')
        ->click('[data-cancel-confirm]')
        ->assertSee('Request cancelled.')
        ->assertNoSmoke();
});
