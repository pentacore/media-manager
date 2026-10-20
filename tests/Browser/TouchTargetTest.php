<?php

declare(strict_types=1);

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
        ->assertScript(touchTargetHitAboveScript('[data-view-toggle="table"]', 6), true)
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
    $webpage->assertScript('getComputedStyle(document.querySelector("[data-movie-open-external]")).opacity', '1');

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
