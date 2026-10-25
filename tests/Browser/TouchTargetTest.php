<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\BenchmarkSchedule;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * True when the element is shorter than 40 px and a point $outside px above
 * its top edge still hits it — i.e. its hit area is larger than its box.
 */
function touchTargetHitAboveScript(string $selector, int $outside): string
{
    $encoded = json_encode($selector, JSON_THROW_ON_ERROR);

    return "(() => { const element = document.querySelector({$encoded}); element.scrollIntoView({ block: 'center', inline: 'center' }); const rect = element.getBoundingClientRect(); const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top - {$outside}); return rect.height < 40 && !!hit && (hit === element || element.contains(hit)); })()";
}

/**
 * True when the area a tap hits — the element's box or its touch-target
 * ::after, whichever is larger — is at least 40 px tall and wide. Walks out
 * from the centre with document.elementFromPoint(), so a clip by a scroller
 * or a neighbour painted on top shrinks the measured area.
 */
function touchTargetTappableScript(string $selector): string
{
    $encoded = json_encode($selector, JSON_THROW_ON_ERROR);

    return "(() => { const element = document.querySelector({$encoded}); element.scrollIntoView({ block: 'center', inline: 'center' }); const rect = element.getBoundingClientRect(); const x = rect.left + rect.width / 2; const y = rect.top + rect.height / 2; const hits = (px, py) => { const hit = document.elementFromPoint(px, py); return !!hit && element.contains(hit); }; const reach = (dx, dy) => { let step = 0; while (step < 60 && hits(x + dx * (step + 1), y + dy * (step + 1))) { step++; } return step; }; return Math.min(reach(0, -1) + reach(0, 1), reach(-1, 0) + reach(1, 0)) >= 39; })()";
}

/**
 * True when a tap 2 px inside the right edge of $selector's previous sibling
 * still hits that sibling, and a tap 2 px inside $selector's left edge hits
 * $selector — i.e. neither control's hit area takes the other's taps.
 */
function touchTargetNeighbourScript(string $selector): string
{
    $encoded = json_encode($selector, JSON_THROW_ON_ERROR);

    return "(() => { const later = document.querySelector({$encoded}); const earlier = later.previousElementSibling; later.scrollIntoView({ block: 'center', inline: 'center' }); const a = earlier.getBoundingClientRect(); const b = later.getBoundingClientRect(); const hitA = document.elementFromPoint(a.right - 2, a.top + a.height / 2); const hitB = document.elementFromPoint(b.left + 2, b.top + b.height / 2); return !!hitA && earlier.contains(hitA) && !!hitB && later.contains(hitB); })()";
}

/**
 * Waits until the page's deferred library has replaced its skeleton and
 * $selector is rendered.
 */
function touchTargetLibraryLoadedScript(string $selector): string
{
    return confirmDialogWaitUntilScript('!document.querySelector("main [data-slot=\"skeleton\"]") && document.querySelector('.json_encode($selector, JSON_THROW_ON_ERROR).')');
}

/**
 * The interactive elements inside `main` that have a visible box but an
 * effective opacity of 0 (their own or an ancestor's), as a JSON list of
 * "tag[hook]" strings. On a phone nothing can hover, so each one is a
 * control only a mouse can reveal.
 */
function touchTargetHoverOnlyScript(): string
{
    return <<<'JS_WRAP'
        (() => {
            const main = document.querySelector('main');
            const hidden = [];
            for (const element of main.querySelectorAll('a[href], button, input:not([type="hidden"]), select, textarea, [role="button"], [role="checkbox"], [role="switch"], [role="link"]')) {
                if (element.closest('[aria-hidden="true"]')) {
                    continue;
                }
                const rect = element.getBoundingClientRect();
                const style = getComputedStyle(element);
                if (rect.width === 0 || rect.height === 0 || style.visibility !== 'visible' || style.pointerEvents === 'none') {
                    continue;
                }
                let opacity = 1;
                for (let node = element; node && node !== main.parentElement; node = node.parentElement) {
                    opacity *= Number(getComputedStyle(node).opacity);
                }
                if (opacity === 0) {
                    const hook = [...element.attributes].map((attribute) => attribute.name).find((name) => name.startsWith('data-')) ?? '';
                    hidden.push(`${element.tagName.toLowerCase()}[${hook}]`);
                }
            }
            return JSON.stringify(hidden);
        })()
    JS_WRAP;
}

function touchTargetFakeLibraries(): void
{
    $poster = [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'title' => 'Example Show', 'titleSlug' => 'example-show', 'year' => 2024, 'status' => 'continuing', 'monitored' => true, 'qualityProfileId' => 1, 'images' => $poster, 'seasons' => [], 'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0]],
        ]),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 10, 'title' => 'Dune', 'titleSlug' => 'dune-2021', 'year' => 2021, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => $poster],
        ]),
        'radarr.local:7878/*' => Http::response([]),
    ]);
}

test('buttons, checkboxes and the view toggles have a 40 px hit area on a phone', function (): void {
    touchTargetFakeLibraries();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript(touchTargetHitAboveScript('[data-add-series]', 5), true)
        ->assertScript(touchTargetHitAboveScript('[data-bulk-select-all] [role="checkbox"]', 10), true)
        ->assertScript(touchTargetTappableScript('[data-view-toggle="grid"]'), true)
        ->assertScript(touchTargetTappableScript('[data-view-toggle="table"]'), true)
        ->assertScript(touchTargetNeighbourScript('[data-view-toggle="table"]'), true)
        ->assertAttribute('[data-view-toggle="table"]', 'aria-label', 'Table view')
        ->assertAttribute('[data-view-toggle="grid"]', 'aria-pressed', 'true');
});

test('at desktop width the hit areas are the visual boxes', function (): void {
    touchTargetFakeLibraries();
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript(touchTargetHitAboveScript('[data-add-series]', 5), false)
        ->assertScript(touchTargetHitAboveScript('[data-bulk-select-all] [role="checkbox"]', 10), false);
});

test("the movie card's Open in Radarr link shows without hover on a phone and waits for hover at desktop", function (): void {
    touchTargetFakeLibraries();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.movies.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(touchTargetLibraryLoadedScript('[data-movie-open-external]'));
    $webpage->assertScript('getComputedStyle(document.querySelector("[data-movie-open-external]")).opacity', '1')
        ->assertScript(touchTargetTappableScript('[data-movie-open-external]'), true);

    $desktop = visit(route('media.movies.index', absolute: false))->assertNoSmoke();
    $desktop->script(touchTargetLibraryLoadedScript('[data-movie-open-external]'));
    $desktop->assertScript('getComputedStyle(document.querySelector("[data-movie-open-external]")).opacity', '0');
});

test('no control in main is hidden behind hover at phone width', function (): void {
    touchTargetFakeLibraries();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(touchTargetLibraryLoadedScript('[data-series-card]'));
    $webpage->assertScript(touchTargetHoverOnlyScript(), '[]');

    $webpage->navigate(route('media.movies.index', absolute: false));
    $webpage->script(touchTargetLibraryLoadedScript('[data-movie-card]'));
    $webpage->assertScript(touchTargetHoverOnlyScript(), '[]');
});

test("a time window strip option is a 40 px target that leaves its neighbour's taps alone on a phone", function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('statistics.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(touchTargetTappableScript('[data-filter-strip] button:nth-child(1)'), true)
        ->assertScript(touchTargetTappableScript('[data-filter-strip] button:nth-child(2)'), true)
        ->assertScript(touchTargetNeighbourScript('[data-filter-strip] button:nth-child(2)'), true);
});

test('neighbouring row actions keep their own taps on a phone', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    touchTargetFakeLibraries();
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sabnzbd.local:8080', 'api_key' => 'k']);
    Http::fake(['sabnzbd.local:8080/api*' => Http::response([
        'status' => true,
        'queue' => ['paused' => false, 'speed' => '10 M', 'speedlimit' => '', 'speedlimit_abs' => '', 'slots' => [
            ['nzo_id' => 'SABnzbd_nzo_1', 'filename' => 'Example.Show.S01E01', 'cat' => 'tv', 'size' => '1.0 GB', 'sizeleft' => '0.5 GB', 'percentage' => '50', 'timeleft' => '0:05:00', 'status' => 'Downloading', 'priority' => '0'],
        ]],
        'history' => ['slots' => [], 'noofslots' => 0],
    ])]);
    AiModelPrice::factory()->create();
    AiFreeUsagePool::factory()->create();
    BenchmarkSchedule::factory()->create(['name' => 'Example nightly']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.connections.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(touchTargetNeighbourScript('[data-connection-menu]'), true);

    $webpage->navigate(route('admin.ai-prices.index', absolute: false));
    $webpage->script(touchTargetLibraryLoadedScript('[data-pool-delete]'));
    $webpage->assertScript(touchTargetNeighbourScript('[data-price-delete]'), true)
        ->assertScript(touchTargetNeighbourScript('[data-pool-delete]'), true);

    $webpage->navigate(route('sabnzbd.queue.index', absolute: false));
    $webpage->script(touchTargetLibraryLoadedScript('[data-sab-card-delete]'));
    $webpage->assertScript(touchTargetNeighbourScript('[data-sab-card-delete]'), true)
        ->assertAttribute('[data-sab-card-delete]', 'aria-label', 'Delete Example.Show.S01E01')
        ->assertScript('document.querySelector("[data-sab-card-delete]").previousElementSibling.getAttribute("aria-label")', 'Pause Example.Show.S01E01');

    $webpage->navigate(route('admin.benchmarks.schedules.index', absolute: false));
    $webpage->script(touchTargetLibraryLoadedScript('[data-benchmark-schedule-delete]'));
    $webpage->assertScript(touchTargetNeighbourScript('[data-benchmark-schedule-delete]'), true);
});

test('the hand-rolled activity strips give each option a 40 px target on a phone', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(touchTargetTappableScript('[data-activity-tab="history"]'), true)
        ->assertScript(touchTargetNeighbourScript('[data-activity-tab="history"]'), true)
        ->assertScript(touchTargetTappableScript('[data-service-filter="radarr"]'), true)
        ->assertScript(touchTargetNeighbourScript('[data-service-filter="radarr"]'), true);

    $webpage->navigate(route('activity-log', absolute: false));
    $webpage->script(appHydratedScript());
    $webpage->assertScript(touchTargetTappableScript('[data-filter-strip] button:last-child'), true)
        ->assertScript(touchTargetNeighbourScript('[data-filter-strip] button:last-child'), true);
});

test('the dialog close button is a 40 px target on a phone', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-users-invite]')
        ->assertVisible('[data-slot="dialog-close"]')
        ->assertScript(touchTargetTappableScript('[data-slot="dialog-close"]'), true);
});

test('a touch screen at desktop width gets the hit areas and the touch reveal', function (): void {
    touchTargetFakeLibraries();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->iPadPro()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript('matchMedia("(pointer: coarse)").matches && innerWidth >= 640', true)
        ->assertScript(touchTargetHitAboveScript('[data-add-series]', 5), true)
        ->assertScript(touchTargetTappableScript('[data-view-toggle="grid"]'), true)
        ->assertScript(touchTargetNeighbourScript('[data-view-toggle="table"]'), true);

    $webpage->navigate(route('media.movies.index', absolute: false));
    $webpage->script(touchTargetLibraryLoadedScript('[data-movie-open-external]'));
    $webpage->assertScript('getComputedStyle(document.querySelector("[data-movie-open-external]")).opacity', '1')
        ->assertScript(touchTargetTappableScript('[data-movie-open-external]'), true);
});

test("the dashboard's per-action Review link is a 40 px target that opens that action's sheet on a phone", function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "Severance"']);
    $this->actingAs(User::factory()->member()->create());

    $selector = sprintf('[data-dashboard-review-action="%d"]', $actionRequest->id);
    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript(touchTargetTappableScript($selector), true)
        ->assertAttribute($selector, 'href', route('actions.requests.index', ['request' => $actionRequest->id], absolute: false))
        ->click($selector);
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-action-sheet] [data-action-title]")'));

    $webpage->assertSeeIn('[data-action-sheet] [data-action-title]', 'Severance')
        ->assertNoSmoke();
});
