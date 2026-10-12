<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\WhisparrVersion;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Records every Inertia request the page starts, prefetches included, as
 * {path, prefetch, partial}. Inertia fires `inertia:start` for every request
 * it sends and none when a click reuses a cached prefetch, so the record
 * tells a hover prefetch, a fresh page visit and a deferred-prop reload
 * apart.
 */
function prefetchRecorderScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmVisits = [];
            document.addEventListener('inertia:start', (event) => {
                const visit = event.detail.visit;
                window.__mmVisits.push({
                    path: visit.url.pathname,
                    prefetch: visit.prefetch === true,
                    partial: visit.only.length > 0 || visit.except.length > 0,
                });
            });
        })()
    JS;
}

/**
 * A JavaScript expression counting the recorded full-page requests for the
 * path: hover prefetches when $prefetch is true, otherwise fresh visits.
 * Partial reloads (deferred props) never count.
 */
function prefetchCountScript(string $path, bool $prefetch): string
{
    return sprintf(
        "window.__mmVisits.filter((visit) => visit.path === '%s' && visit.prefetch === %s && !visit.partial).length",
        $path,
        $prefetch ? 'true' : 'false',
    );
}

/**
 * Polls inside the page until the path's hover prefetch has started.
 */
function prefetchWaitForScript(string $path): string
{
    return confirmDialogWaitUntilScript(sprintf('%s > 0', prefetchCountScript($path, true)));
}

/**
 * Polls inside the page until the SPA navigation reached the path.
 */
function prefetchWaitForPathScript(string $path): string
{
    return confirmDialogWaitUntilScript(sprintf("window.location.pathname === '%s'", $path));
}

/**
 * Rests inside the page for well over Inertia's 75 ms hover delay without
 * any further Playwright pointer action, so a test can hover a link once and
 * then prove its prefetch never started — rather than moving the pointer to
 * a second, control link, which fires that element's native `mouseleave` and
 * cancels the first link's pending hover timer before it ever reaches
 * `router.prefetch()`.
 */
function prefetchRestPastHoverDelayScript(): string
{
    return '(async () => { await new Promise((resolve) => setTimeout(resolve, 300)); })()';
}

test('hovering a sidebar link prefetches its page and the click reuses the prefetch', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(prefetchRecorderScript());

    $webpage->hover('[data-nav-item="Action Queue"]');
    $webpage->script(prefetchWaitForScript($actionQueuePath));

    expect($webpage->script(prefetchCountScript($actionQueuePath, true)))->toBe(1);

    $webpage->click('[data-nav-item="Action Queue"]');
    $webpage->script(prefetchWaitForPathScript($actionQueuePath));

    $webpage->assertPathIs($actionQueuePath)->assertNoSmoke();
    expect($webpage->script(prefetchCountScript($actionQueuePath, false)))->toBe(0);
});

test('hovering the link of the page already open prefetches nothing', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $dashboardPath = route('dashboard', absolute: false);

    $webpage = visit($dashboardPath)->assertNoSmoke();
    $webpage->script(prefetchRecorderScript());

    // Rest on the open page's own link, past the 75 ms hover delay, without
    // moving the pointer anywhere else. router.prefetch() in @inertiajs/core
    // refuses a target equal to the current URL (node_modules/@inertiajs/core/
    // dist/index.js:3412-3414), so this exercises that guard directly: a
    // regression that dropped it would show up as a prefetch for this exact
    // path, not as an early read before the timer fired.
    $webpage->hover('[data-nav-item="Dashboard"]');
    $webpage->script(prefetchRestPastHoverDelayScript());

    expect($webpage->script(prefetchCountScript($dashboardPath, true)))->toBe(0);
});

test('the Downloads link never prefetches because its page reads SABnzbd live', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $downloadsPath = route('sabnzbd.queue.index', absolute: false);
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(prefetchRecorderScript());

    $webpage->hover('[data-nav-item="Downloads"]');
    $webpage->hover('[data-nav-item="Action Queue"]');
    $webpage->script(prefetchWaitForScript($actionQueuePath));

    expect($webpage->script(prefetchCountScript($actionQueuePath, true)))->toBe(1)
        ->and($webpage->script(prefetchCountScript($downloadsPath, true)))->toBe(0);
});

test('the Subtitles link never prefetches because its overview reads Bazarr live, uncached on failure', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $subtitlesPath = route('bazarr.overview', absolute: false);
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(prefetchRecorderScript());

    $webpage->hover('[data-nav-item="Subtitles"]');
    $webpage->hover('[data-nav-item="Action Queue"]');
    $webpage->script(prefetchWaitForScript($actionQueuePath));

    expect($webpage->script(prefetchCountScript($actionQueuePath, true)))->toBe(1)
        ->and($webpage->script(prefetchCountScript($subtitlesPath, true)))->toBe(0);
});

test('the Seasonal Anime link never prefetches because index() can dispatch a bootstrap job on GET', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $animePath = route('media.anime.index', absolute: false);
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(prefetchRecorderScript());

    $webpage->hover('[data-nav-item="Seasonal Anime"]');
    $webpage->hover('[data-nav-item="Action Queue"]');
    $webpage->script(prefetchWaitForScript($actionQueuePath));

    expect($webpage->script(prefetchCountScript($actionQueuePath, true)))->toBe(1)
        ->and($webpage->script(prefetchCountScript($animePath, true)))->toBe(0);
});

test('the AI Usage link never prefetches because index() builds its aggregates eagerly', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->actingAs(User::factory()->admin()->create());
    $aiUsagePath = route('admin.ai-usage.index', absolute: false);
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    // The link lives in the collapsed "AI" admin sub-group.
    $webpage->click('[data-sidebar="content"] button:has-text("AI")');
    $webpage->script(prefetchRecorderScript());

    $webpage->hover('[data-nav-item="AI Usage"]');
    $webpage->hover('[data-nav-item="Action Queue"]');
    $webpage->script(prefetchWaitForScript($actionQueuePath));

    expect($webpage->script(prefetchCountScript($actionQueuePath, true)))->toBe(1)
        ->and($webpage->script(prefetchCountScript($aiUsagePath, true)))->toBe(0);
});

test('hovering a series card prefetches the series page and the click reuses it', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    $series = [
        'id' => 7, 'title' => 'Severance', 'titleSlug' => 'severance', 'year' => 2022, 'status' => 'continuing',
        'monitored' => true, 'qualityProfileId' => 1, 'images' => [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']],
        'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 1, 'sizeOnDisk' => 10]]],
        'statistics' => ['sizeOnDisk' => 10, 'episodeCount' => 1, 'episodeFileCount' => 1],
    ];
    Http::fake([
        'sonarr.local:8989/api/v3/series/7' => Http::response($series),
        'sonarr.local:8989/api/v3/series' => Http::response([$series]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
    $this->actingAs(User::factory()->member()->create());
    $showPath = route('media.series.show', ['id' => 7], absolute: false);

    $webpage = visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="7"]', 'Severance');
    $webpage->script(prefetchRecorderScript());

    $webpage->hover('[data-series-card="7"]');
    $webpage->script(prefetchWaitForScript($showPath));
    $webpage->click('[data-series-card="7"]');
    $webpage->script(prefetchWaitForPathScript($showPath));

    $webpage->assertPathIs($showPath)->assertNoSmoke();
    expect($webpage->script(prefetchCountScript($showPath, true)))->toBe(1)
        ->and($webpage->script(prefetchCountScript($showPath, false)))->toBe(0);
});

test('hovering a movie card prefetches the movie page and the click reuses it', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    $movie = ['id' => 7, 'title' => 'Arrival', 'titleSlug' => 'arrival', 'year' => 2016, 'status' => 'released', 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 2048, 'images' => [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']]];
    Http::fake([
        'radarr.local:7878/api/v3/movie/7' => Http::response($movie),
        'radarr.local:7878/api/v3/movie' => Http::response([$movie]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
    $this->actingAs(User::factory()->member()->create());
    $showPath = route('media.movies.show', ['id' => 7], absolute: false);

    $webpage = visit(route('media.movies.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-movie-card="7"]', 'Arrival');
    $webpage->script(prefetchRecorderScript());

    $webpage->hover('[data-movie-card="7"]');
    $webpage->script(prefetchWaitForScript($showPath));
    $webpage->click('[data-movie-card="7"]');
    $webpage->script(prefetchWaitForPathScript($showPath));

    $webpage->assertPathIs($showPath)->assertNoSmoke();
    expect($webpage->script(prefetchCountScript($showPath, true)))->toBe(1)
        ->and($webpage->script(prefetchCountScript($showPath, false)))->toBe(0);
});

test('hovering a Whisparr card prefetches the title page and the click reuses it', function (): void {
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    $item = ['id' => 11, 'title' => 'Aurora Scene', 'year' => 2024, 'monitored' => true, 'hasFile' => true, 'sizeOnDisk' => 3_000_000_000, 'qualityProfileId' => 1, 'images' => [], 'path' => '/data/whisparr/Aurora Scene', 'overview' => 'First.'];
    Http::fake([
        'whisparr.local:6969/api/v3/movie/11*' => Http::response($item),
        'whisparr.local:6969/api/v3/movie' => Http::response([$item]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
    ]);
    $this->actingAs(User::factory()->admin()->create());
    $showPath = route('media.whisparr.show', ['id' => 11], absolute: false);

    $webpage = visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-card="11"] [data-whisparr-title]', 'Aurora Scene');
    $webpage->script(prefetchRecorderScript());

    $webpage->hover('[data-whisparr-card="11"]');
    $webpage->script(prefetchWaitForScript($showPath));
    $webpage->click('[data-whisparr-card="11"]');
    $webpage->script(prefetchWaitForPathScript($showPath));

    $webpage->assertPathIs($showPath)->assertNoSmoke();
    expect($webpage->script(prefetchCountScript($showPath, true)))->toBe(1)
        ->and($webpage->script(prefetchCountScript($showPath, false)))->toBe(0);
});

/**
 * Records the path of every hover prefetch that has landed (Inertia fires
 * `inertia:prefetched` with the response, before any click reuses it).
 */
function prefetchLandedRecorderScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmPrefetched = [];
            document.addEventListener('inertia:prefetched', (event) => {
                window.__mmPrefetched.push(event.detail.visit.url.pathname);
            });
        })()
    JS;
}

test('a click that reuses a prefetch keeps a badge count that changed live after the hover', function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '3');
    $webpage->script(appHydratedScript());
    $webpage->script(prefetchRecorderScript());
    $webpage->script(prefetchLandedRecorderScript());

    $webpage->hover('[data-nav-item="Action Queue"]');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("window.__mmPrefetched.includes('%s')", $actionQueuePath)));

    expect($webpage->script('window.__mmPrefetched'))->toContain($actionQueuePath);

    // Settled elsewhere after the prefetch was fetched: its `nav` still says 3.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $pending[0]->id,
        'status' => 'approved',
        'result' => null,
        'updated_at' => $pending[0]->updated_at->addSeconds(5)->toISOString(),
        'pending_count' => 2,
    ]));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-nav-badge=\"Action Queue\"]')?.innerText.trim() === '2'"));

    $webpage->click('[data-nav-item="Action Queue"]');
    $webpage->script(prefetchWaitForPathScript($actionQueuePath));

    $webpage->assertPathIs($actionQueuePath)
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '2')
        ->assertNoSmoke();
    // The click reused the prefetch (no fresh visit), so the stale `nav` was offered.
    expect($webpage->script(prefetchCountScript($actionQueuePath, false)))->toBe(0);
});

test("a page's own deferred reload does not make a later click forget its prefetch's date", function (): void {
    $pending = ActionRequest::factory()->count(3)->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->admin()->create());
    $dashboardPath = route('dashboard', absolute: false);
    $actionQueuePath = route('actions.requests.index', absolute: false);

    $webpage = visit($actionQueuePath)
        ->assertNoSmoke()
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '3');
    $webpage->script(appHydratedScript());
    $webpage->script(prefetchRecorderScript());
    $webpage->script(prefetchLandedRecorderScript());

    // Hover prefetch of the Dashboard (its `nav` says 3), reused by the click.
    $webpage->hover('[data-nav-item="Dashboard"]');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("window.__mmPrefetched.includes('%s')", $dashboardPath)));

    expect($webpage->script('window.__mmPrefetched'))->toContain($dashboardPath);
    $webpage->click('[data-nav-item="Dashboard"]');
    $webpage->script(prefetchWaitForPathScript($dashboardPath));

    // The Dashboard's deferred `nowPlaying` reload: a real request for its URL.
    $webpage->script(confirmDialogWaitUntilScript(sprintf("window.__mmVisits.some((visit) => visit.path === '%s' && visit.partial)", $dashboardPath)));

    expect($webpage->script(sprintf("window.__mmVisits.filter((visit) => visit.path === '%s' && visit.partial).length", $dashboardPath)))->toBeGreaterThan(0);

    $webpage->click('[data-nav-item="Action Queue"]');
    $webpage->script(prefetchWaitForPathScript($actionQueuePath));

    // Settled elsewhere after the Dashboard prefetch was fetched.
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestStatusChanged', [
        'id' => $pending[0]->id,
        'status' => 'approved',
        'result' => null,
        'updated_at' => $pending[0]->updated_at->addSeconds(5)->toISOString(),
        'pending_count' => 2,
    ]));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-nav-badge=\"Action Queue\"]')?.innerText.trim() === '2'"));
    $webpage->assertSeeIn('[data-nav-badge="Action Queue"]', '2');

    // Within the 10 s cache window the click reuses the hover-time Dashboard response.
    $webpage->click('[data-nav-item="Dashboard"]');
    $webpage->script(prefetchWaitForPathScript($dashboardPath));

    $webpage->assertPathIs($dashboardPath)
        ->assertSeeIn('[data-nav-badge="Action Queue"]', '2')
        ->assertNoSmoke();
    expect($webpage->script(prefetchCountScript($dashboardPath, true)))->toBe(1)
        ->and($webpage->script(prefetchCountScript($dashboardPath, false)))->toBe(0);
});
