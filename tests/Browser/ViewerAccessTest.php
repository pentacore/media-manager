<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Every leaf link a viewer's desktop sidebar may render. Each one is
 * covered by the "viewer read routes" dataset in AbilityRouteMatrixTest,
 * which asserts it opens with 200 — together they pin "no link a viewer
 * can see returns 403".
 *
 * @return list<string>
 */
function viewerSidebarPaths(): array
{
    $paths = [
        '/dashboard',
        '/media/series',
        '/media/movies',
        '/media/discover',
        '/media/requests/mine',
        '/monitoring/now-playing',
        '/monitoring/watch-history',
    ];

    sort($paths);

    return $paths;
}

function viewerSidebarPathsScript(): string
{
    return 'JSON.stringify(Array.from(document.querySelectorAll(\'[data-sidebar="content"] a[href]\')).map((a) => new URL(a.href).pathname).sort())';
}

test('a viewer sidebar lists exactly the pages a viewer can open', function (): void {
    // The nav item only shows with an active Seerr connection; the dashboard
    // itself never calls Seerr, so this host-scoped fake is only a guard
    // against a stray lookup — never a bare '*' catch-all (see browser.md).
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake(['seerr.local:5055/*' => Http::response([])]);

    $this->actingAs(User::factory()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="content"]', 'TV Series')
        ->assertScript(viewerSidebarPathsScript(), json_encode(viewerSidebarPaths(), JSON_UNESCAPED_SLASHES));
});

test('a viewer sees the series page without any action control', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series/7' => Http::response([
            'id' => 7, 'title' => 'Severance', 'titleSlug' => 'severance', 'year' => 2022, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [],
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 1, 'sizeOnDisk' => 10]]],
            'statistics' => ['sizeOnDisk' => 10, 'episodeCount' => 1, 'episodeFileCount' => 1],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 70, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Good News About Hell', 'airDate' => '2022-02-18', 'hasFile' => true, 'monitored' => true],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);

    $this->actingAs(User::factory()->create());

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->assertSee('Severance')
        ->click('Season 1')
        ->assertSee('Good News About Hell')
        ->assertCount('[data-delete-trigger]', 0)
        ->assertCount('[data-replacement-trigger]', 0)
        // Additional hardening: the connection host/port is internal detail —
        // no "Open in Sonarr" deep link for a viewer.
        ->assertDontSee('Open in Sonarr');
});

// Split into two tests rather than switching actingAs() mid-test: pest-plugin-browser
// reuses the same browser session across visit() calls within one test, and a second
// actingAs() call does not re-authenticate that session for the next visit() (proven
// with an isolated repro — a member visiting fresh, alone, correctly sees the add
// button; no other test in this suite switches actingAs() mid-test).
test('a viewer sees the library index without the add button', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->create());
    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-add-series]', 0)
        // Additional hardening: same internal-host concern as the show page.
        ->assertDontSee('Open Sonarr');
});

test('a member sees the library index with the add button', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->member()->create());
    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-add-series]', 1)
        ->assertSee('Open Sonarr');
});

test('a viewer searching gets no scope switcher', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake(['seerr.local:5055/api/v1/search*' => Http::response(['results' => []])]);

    $this->actingAs(User::factory()->create());

    visit(route('media.search.index', ['q' => 'dune'], absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-search-scope]', 0);
});
