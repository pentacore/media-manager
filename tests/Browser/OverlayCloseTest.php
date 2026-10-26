<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * A Seerr discover row with one show whose overview is long enough that the
 * title sheet scrolls on a short phone screen.
 */
function overlayCloseFakeDiscover(): void
{
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => []]),
        'seerr.local:5055/api/v1/discover/trending*' => Http::response(['results' => [
            ['id' => 95396, 'mediaType' => 'tv', 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'posterPath' => '/severance.jpg'],
        ]]),
        'seerr.local:5055/api/v1/discover/*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/tv/95396' => Http::response([
            'id' => 95396, 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'posterPath' => '/severance.jpg',
            'overview' => str_repeat('An example overview that runs long on purpose. ', 40),
            'numberOfSeasons' => 2, 'seasons' => [['seasonNumber' => 1, 'name' => 'Season 1', 'episodeCount' => 9], ['seasonNumber' => 2, 'name' => 'Season 2', 'episodeCount' => 10]],
        ]),
    ]);
}

/**
 * True when $close sits inside the top 56 px of $frame and a tap on its
 * centre lands on it — i.e. it is on screen and nothing paints over it.
 */
function overlayCloseReachableScript(string $frame, string $close): string
{
    $frameJson = json_encode($frame, JSON_THROW_ON_ERROR);
    $closeJson = json_encode($close, JSON_THROW_ON_ERROR);

    return "(() => { const frame = document.querySelector({$frameJson}).getBoundingClientRect(); const close = document.querySelector({$closeJson}); const rect = close.getBoundingClientRect(); const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2); return rect.top >= Math.max(frame.top, 0) - 1 && rect.bottom <= Math.max(frame.top, 0) + 56 && !!hit && close.contains(hit); })()";
}

test('a long dialog keeps its close button at the top while its body scrolls on a phone', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))->on()->mobile()->assertNoSmoke();
    // 360 px stands in for a phone with the on-screen keyboard up.
    $webpage->resize(375, 360);
    $webpage->script(appHydratedScript());
    $webpage->click('[data-users-invite]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[role=\"dialog\"]") && document.querySelector("[role=\"dialog\"]").getAnimations().length === 0'));

    // Unscrolled, the X sits where `absolute top-4 right-4` put it (the 1 px
    // border is subtracted, because the X sits inside the border box).
    $webpage->assertScript('(() => { const el = document.querySelector("[role=\"dialog\"]"); const dialog = el.getBoundingClientRect(); const close = document.querySelector("[data-slot=\"dialog-close\"]").getBoundingClientRect(); return Math.round(close.top - dialog.top - el.clientTop) === 16 && Math.round(dialog.right - close.right - el.clientLeft) === 16; })()', true)
        ->assertScript('(() => { const body = document.querySelector("[data-slot=\"dialog-body\"]"); return body.scrollHeight > body.clientHeight; })()', true);

    // The dialog's own box never scrolls; only `[data-slot="dialog-body"]`
    // does, so the X (an `absolute` sibling, not a child of the body) never
    // moves.
    $webpage->script('(() => { const body = document.querySelector("[data-slot=\"dialog-body\"]"); body.scrollTop = body.scrollHeight; })()');
    $webpage->assertScript(overlayCloseReachableScript('[role="dialog"]', '[data-slot="dialog-close"]'), true)
        ->click('[data-slot="dialog-close"]');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[role=\"dialog\"]")'));
    $webpage->assertMissing('[role="dialog"]');
});

test('a long sheet keeps its close button on screen while it scrolls on a phone', function (): void {
    overlayCloseFakeDiscover();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.discover.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->resize(375, 420);
    $webpage->script(appHydratedScript());
    $webpage->click('[data-title-card="tv-95396"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-title-sheet]") && document.querySelector("[data-title-sheet]").innerText.includes("Season 2") && document.querySelector("[data-title-sheet]").getAnimations().length === 0'));

    $webpage->assertScript('(() => { const sheet = document.querySelector("[data-title-sheet]"); return sheet.scrollHeight > sheet.clientHeight; })()', true);
    $webpage->script('(() => { const sheet = document.querySelector("[data-title-sheet]"); sheet.scrollTop = sheet.scrollHeight; })()');

    $webpage->assertScript(overlayCloseReachableScript('[data-title-sheet]', '[data-title-sheet] [data-sheet-close]'), true)
        ->click('[data-title-sheet] [data-sheet-close]');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-title-sheet]")'));
    $webpage->assertMissing('[data-title-sheet]');
});

test('at desktop width the dialog and sheet close buttons keep their absolute place', function (): void {
    overlayCloseFakeDiscover();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-users-invite]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-slot=\"dialog-close\"]")'));
    $webpage->assertScript('getComputedStyle(document.querySelector("[data-slot=\"dialog-close\"]")).position', 'absolute')
        ->assertScript('getComputedStyle(document.querySelector("[role=\"dialog\"]")).display', 'grid')
        ->assertScript('getComputedStyle(document.querySelector("[data-slot=\"dialog-body\"]")).display', 'contents');

    $webpage->navigate(route('media.discover.index', absolute: false));
    $webpage->script(appHydratedScript());
    $webpage->click('[data-title-card="tv-95396"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-title-sheet] [data-sheet-close]")'));
    $webpage->assertScript('getComputedStyle(document.querySelector("[data-title-sheet] [data-sheet-close]")).position', 'absolute');
});

test('the command palette results touch the search form with no gap on a phone', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->keys(':root', 'Meta+k');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-palette-sections]") && document.querySelector("[data-palette-sections]").closest("[role=\"dialog\"]").getAnimations().length === 0'));

    // `[data-slot="dialog-body"]` inherits the frame's `gap` (`gap-0` here),
    // so the results scroller sits flush under the form's border instead of
    // 16 px below it.
    $webpage->assertScript('(() => { const form = document.querySelector("[role=\"dialog\"] form").getBoundingClientRect(); const results = document.querySelector("[data-palette-sections]").parentElement.getBoundingClientRect(); return Math.round(results.top - form.bottom) === 0; })()', true);
});

test('the command palette sits fully on screen on a landscape phone', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->resize(812, 375);
    $webpage->script(appHydratedScript());
    $webpage->keys(':root', 'Meta+k');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-palette-sections]") && document.querySelector("[data-palette-sections]").closest("[role=\"dialog\"]").getAnimations().length === 0'));

    $webpage->assertScript('(() => { const dialog = document.querySelector("[data-palette-sections]").closest("[role=\"dialog\"]").getBoundingClientRect(); const input = document.querySelector("[role=\"dialog\"] input[type=\"search\"]").getBoundingClientRect(); return dialog.top >= 0 && dialog.bottom <= window.innerHeight && input.top >= 0 && input.bottom <= window.innerHeight; })()', true);

    // The results scroller shrinks to fit the short viewport — it never
    // pokes out past the dialog's own bottom edge.
    $webpage->assertScript('(() => { const dialog = document.querySelector("[data-palette-sections]").closest("[role=\"dialog\"]").getBoundingClientRect(); const results = document.querySelector("[data-palette-sections]").parentElement.getBoundingClientRect(); return results.bottom <= dialog.bottom + 1; })()', true);

    // The last link is reachable by scrolling it into view — nothing clips
    // it or pushes the dialog itself (and the input with it) off screen.
    $webpage->script('(() => { const links = document.querySelectorAll("[data-palette-link]"); links[links.length - 1].scrollIntoView({ block: "nearest" }); })()');
    $webpage->assertScript('(() => { const dialog = document.querySelector("[data-palette-sections]").closest("[role=\"dialog\"]").getBoundingClientRect(); const links = document.querySelectorAll("[data-palette-link]"); const last = links[links.length - 1].getBoundingClientRect(); return last.top >= dialog.top - 1 && last.bottom <= dialog.bottom + 1; })()', true);
});
