<?php

declare(strict_types=1);

use App\Models\EmbyActivity;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * True when the first two stat cards share a row, the third starts a new
 * one, and every card ends inside the screen.
 */
function monitoringPhoneTwoPerRowScript(): string
{
    return '(() => { const cards = [...document.querySelectorAll("main [data-stat-card]")]; if (cards.length < 3) { return "only " + cards.length + " stat cards"; } const top = (card) => Math.round(card.getBoundingClientRect().top); return top(cards[0]) === top(cards[1]) && top(cards[2]) > top(cards[0]) && cards.every((card) => card.getBoundingClientRect().right <= window.innerWidth) ? "two per row" : "stacked"; })()';
}

function monitoringPhoneFakes(): void
{
    ServiceConnection::factory()->sabnzbd()->create(['name' => 'SABnzbd', 'url' => 'http://sab.local:8080', 'api_key' => 'k']);
    ServiceConnection::factory()->emby()->create(['name' => 'Emby', 'url' => 'http://emby.local:8096', 'api_key' => 'k']);
    Http::fake([
        'sab.local:8080/api*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['mode'] ?? null) === 'history'
                ? Http::response(['history' => ['noofslots' => 0, 'slots' => []]])
                : Http::response(['queue' => ['paused' => false, 'speed' => '10.2 M', 'sizeleft' => '12.5 GB', 'timeleft' => '1:20:00', 'speedlimit' => '', 'speedlimit_abs' => '', 'noofslots' => 0, 'slots' => []]]);
        },
        'emby.local:8096/Sessions*' => Http::response([
            ['Id' => 's1', 'UserName' => 'Alice', 'UserId' => 'emby-1', 'Client' => 'Emby Theater For Windows', 'DeviceName' => 'An Extremely Long Living Room Device Name', 'NowPlayingItem' => ['Id' => 'i1', 'Name' => 'An Extremely Long Example Movie Title That Wraps Badly', 'Type' => 'Movie', 'RunTimeTicks' => 66_000_000_000], 'PlayState' => ['PositionTicks' => 6_000_000_000, 'IsPaused' => false]],
        ]),
        'emby.local:8096/*' => Http::response([]),
    ]);
}

test('KPI cards sit two per row on the phone-job pages', function (string $routeName): void {
    monitoringPhoneFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route($routeName, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(confirmDialogWaitUntilScript('document.querySelectorAll("main [data-stat-card]").length >= 3 && !document.querySelector("main [data-slot=\"skeleton\"]")'));

    $webpage->assertScript(monitoringPhoneTwoPerRowScript(), 'two per row')
        ->assertScript(phoneOverflowScript('main'), '');
})->with([
    'downloads' => ['sabnzbd.queue.index'],
    'service health' => ['monitoring.service-health'],
    'watch history' => ['monitoring.watch-history'],
    'dashboard' => ['dashboard'],
]);

test('service health rows are two-column cards on a phone', function (): void {
    ServiceConnection::factory()->sonarr()->create(['name' => 'Example Sonarr With A Long Name', 'url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/*' => Http::response([])]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('monitoring.service-health', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertVisible('[data-health-row]')
        ->assertScript('getComputedStyle(document.querySelector("[data-health-row]")).gridTemplateColumns.split(" ").length', 2)
        // The status pill sits on the first line, beside the name.
        ->assertScript('(() => { const row = document.querySelector("[data-health-row]"); const name = row.children[0].getBoundingClientRect(); const status = row.children[4].getBoundingClientRect(); return Math.abs(name.top - status.top) <= 4; })()', true)
        ->assertScript(phoneOverflowScript('main'), '');
});

test('now playing cards keep the device and remaining time inside the card on a phone', function (): void {
    monitoringPhoneFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('monitoring.now-playing', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(confirmDialogWaitUntilScript('document.querySelectorAll("[data-session-card]").length === 1'));

    $webpage->assertScript('(() => { const card = document.querySelector("[data-session-card]"); const box = card.getBoundingClientRect(); return [...card.querySelectorAll("span, div")].filter((element) => element.children.length === 0 && element.textContent.trim() !== "").every((element) => { const rect = element.getBoundingClientRect(); return rect.right <= box.right + 1; }); })()', true)
        ->assertSeeIn('[data-session-card]', 'An Extremely Long Living Room Device Name')
        ->assertSeeIn('[data-session-card]', 'remaining')
        ->assertScript(phoneOverflowScript('main'), '');
});

test('watch history is cards on a phone and its filters stay on screen', function (): void {
    monitoringPhoneFakes();
    $member = User::factory()->member()->create();
    $activity = EmbyActivity::factory()->create(['media_type' => 'movie', 'media_title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'series_title' => null, 'play_position' => 6_000_000_000, 'duration_ticks' => 66_000_000_000]);
    $this->actingAs($member);

    $webpage = visit(route('monitoring.watch-history', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertVisible(sprintf('[data-watch-card="%d"]', $activity->id))
        ->assertSeeIn(sprintf('[data-watch-card="%d"]', $activity->id), 'Wraps Badly')
        ->assertMissing(sprintf('[data-watch-row="%d"]', $activity->id))
        ->assertScript('(() => { const filters = document.querySelector("[data-page-header-filters]").getBoundingClientRect(); return filters.left >= 0 && filters.right <= window.innerWidth; })()', true)
        ->assertScript(phoneOverflowScript('main'), '');
});

test('an empty watch history says so once on a phone', function (): void {
    monitoringPhoneFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('monitoring.watch-history', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertSee('No activity yet.')
        ->assertScript('[...document.querySelectorAll("main *")].filter((element) => element.children.length === 0 && element.textContent.trim() === "No activity yet.").length', 1)
        ->assertCount('[data-watch-cards]', 0)
        ->assertScript('getComputedStyle(document.querySelector("main thead")).display', 'none')
        ->assertScript(phoneOverflowScript('main'), '');
});

test('at desktop width the stat grids keep their columns and watch history keeps its table', function (): void {
    monitoringPhoneFakes();
    $activity = EmbyActivity::factory()->create(['media_title' => 'Example Movie']);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))->assertNoSmoke();
    $webpage->assertScript('(() => { const cards = [...document.querySelectorAll("main [data-stat-card]")]; return cards.length === 4 && cards.every((card) => Math.round(card.getBoundingClientRect().top) === Math.round(cards[0].getBoundingClientRect().top)); })()', true);

    $webpage->navigate(route('monitoring.watch-history', absolute: false));
    $webpage->assertVisible(sprintf('[data-watch-row="%d"]', $activity->id))
        ->assertMissing('[data-watch-cards]');
});
