<?php

declare(strict_types=1);

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<array{coverType: string, remoteUrl: string}>
 */
function bulkBrowserPoster(): array
{
    return [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
}

function fakeBulkSonarrLibrary(ServiceConnection $sonarr, int $count): void
{
    $series = [];

    foreach (range(1, $count) as $number) {
        $title = sprintf('Series %03d', $number);
        $series[] = [
            'id' => $number, 'title' => $title, 'titleSlug' => sprintf('series-%d', $number), 'year' => 2020, 'status' => 'continuing',
            'monitored' => $number % 2 === 0, 'qualityProfileId' => 1, 'images' => bulkBrowserPoster(), 'seasons' => [],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
        ];
        // The describer names the series from the index, so no per-title upstream call is made.
        IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create(['sonarr_id' => $number, 'title' => $title, 'year' => 2020]);
    }

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response($series),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p'], ['id' => 6, 'name' => 'Ultra-HD']]),
    ]);
}

/**
 * Holds the next bulk POST inside the page until `window.__releaseBulk()`
 * is called, so a test can look at the page while the run is in flight
 * without any server-side sleeping.
 */
function holdBulkRequestScript(): string
{
    return <<<'JS'
        (() => {
            const originalFetch = window.fetch;
            window.fetch = (...args) => {
                if (String(args[0]).includes('/bulk')) {
                    return new Promise((resolve) => {
                        window.__releaseBulk = () => resolve(originalFetch(...args));
                    });
                }

                return originalFetch(...args);
            };
        })()
    JS;
}

/**
 * Polls inside the page until the JavaScript condition holds (or ~5 s pass),
 * so evaluate() returns only once an async UI change has landed.
 */
function bulkBrowserWaitUntil(string $condition): string
{
    return <<<JS
        (async () => {
            for (let attempt = 0; attempt < 250; attempt++) {
                if ({$condition}) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
        })()
    JS;
}

beforeEach(function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    Queue::fake([ExecuteActionRequest::class]);
    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
});

test('a member ticks two series, monitors them in bulk and sees the summary', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 3);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select="1"]')
        ->click('[data-bulk-select="3"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-action="monitor"]')
        ->assertSee('2 started')
        ->assertCount('[data-bulk-bar]', 0)
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'monitor_series')->orderBy('id')->pluck('payload')->all())->toEqual([
        ['series_id' => 1, 'monitored' => true, 'service_connection_id' => $this->sonarr->id],
        ['series_id' => 3, 'monitored' => true, 'service_connection_id' => $this->sonarr->id],
    ]);
});

test('select all takes the filtered titles and a filter change clears the selection', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 4);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-monitored-filter="unmonitored"]')
        ->click('[data-bulk-select-all]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-monitored-filter="all"]')
        ->assertCount('[data-bulk-bar]', 0);
});

test('a bulk delete repeats the count and the delete-files choice', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 3);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select="1"]')
        ->click('[data-bulk-select="2"]')
        ->click('[data-bulk-action="delete"]')
        ->assertSeeIn('[data-bulk-delete-description]', '2 series')
        ->click('[data-bulk-delete-files]')
        ->click('[data-bulk-delete-confirm]')
        ->assertSee('2 queued for approval')
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'delete_series')->get()->pluck('payload.delete_files')->all())->toBe([true, true]);
});

test('more than 25 selected asks before searching, then sends one search per title', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 30);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select-all]')
        ->assertSeeIn('[data-bulk-count]', '30 selected')
        ->click('[data-bulk-action="search"]')
        ->assertSeeIn('[data-bulk-search-warning]', '30')
        ->click('[data-bulk-search-confirm]')
        ->assertSee('30 started');

    expect(ActionRequest::query()->where('type', 'search_media')->count())->toBe(30);
});

test('more than 100 selected disables every bulk action', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 101);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select-all]')
        ->assertSeeIn('[data-bulk-count]', '101 selected')
        ->assertPresent('[data-bulk-over-limit]')
        ->assertScript('document.querySelector(\'[data-bulk-action="monitor"]\').disabled === true')
        ->assertScript('document.querySelector(\'[data-bulk-action="delete"]\').disabled === true');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('a member changes the quality profile of several movies at once', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    $movies = [];

    foreach ([10 => 'Dune', 11 => 'Arrival'] as $id => $title) {
        $movies[] = ['id' => $id, 'title' => $title, 'titleSlug' => strtolower($title), 'year' => 2021, 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => bulkBrowserPoster()];
        IndexedMovie::factory()->for($radarr, 'serviceConnection')->create(['radarr_id' => $id, 'title' => $title, 'year' => 2021]);
    }

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response($movies),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p'], ['id' => 6, 'name' => 'Ultra-HD']]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.movies.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-movie-card="10"]', 'Dune')
        ->click('[data-bulk-select="10"]')
        ->click('[data-bulk-select="11"]')
        ->click('[data-bulk-quality-profile-trigger]')
        ->click('[data-bulk-quality-profile-option="6"]')
        ->assertSee('2 started');

    expect(ActionRequest::query()->where('type', 'set_movie_quality_profile')->get()->pluck('payload.quality_profile_id')->all())->toBe([6, 6]);
});

test('a viewer sees no selection controls', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 2);
    $this->actingAs(User::factory()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->assertCount('[data-bulk-select]', 0)
        ->assertCount('[data-bulk-select-all]', 0);
});

test('a series that drops out of the library during a reload drops out of the bulk selection too', function (): void {
    $full = [
        ['id' => 1, 'title' => 'Series 001', 'titleSlug' => 'series-1', 'year' => 2020, 'status' => 'continuing', 'monitored' => false, 'qualityProfileId' => 1, 'images' => bulkBrowserPoster(), 'seasons' => [], 'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0]],
        ['id' => 2, 'title' => 'Series 002', 'titleSlug' => 'series-2', 'year' => 2020, 'status' => 'continuing', 'monitored' => false, 'qualityProfileId' => 1, 'images' => bulkBrowserPoster(), 'seasons' => [], 'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0]],
        ['id' => 3, 'title' => 'Series 003', 'titleSlug' => 'series-3', 'year' => 2020, 'status' => 'continuing', 'monitored' => false, 'qualityProfileId' => 1, 'images' => bulkBrowserPoster(), 'seasons' => [], 'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0]],
    ];
    // Series 002 is gone from Sonarr's own list by the next reload (e.g.
    // deleted there directly). Http::fake() appends stubs rather than
    // replacing them — a second Http::fake() call for the same URL would
    // never be reached, the first-registered stub always matches first — so
    // this is one registration with a stateful closure (held on an object,
    // never a by-reference variable, so it is readable after the fact too).
    $state = new class
    {
        public int $calls = 0;
    };
    Http::fake([
        'sonarr.local:8989/api/v3/series' => function () use ($state, $full) {
            $state->calls++;

            return Http::response($state->calls === 1 ? $full : [$full[0], $full[2]]);
        },
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p'], ['id' => 6, 'name' => 'Ultra-HD']]),
    ]);
    foreach ($full as $series) {
        IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => $series['id'], 'title' => $series['title'], 'year' => $series['year']]);
    }

    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select="1"]')
        ->click('[data-bulk-select="2"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected');

    // A plain upstream fake swap alone would still be served from the warm
    // list cache, same as production — bust it so the Sync reload actually
    // asks Sonarr again (and reaches the closure's second call).
    new SonarrCache($this->sonarr)->bustAll();

    $webpage->click('Sync')
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->assertCount('[data-series-card="2"]', 0)
        ->assertCount('[data-bulk-select="2"]', 0)
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->assertNoSmoke();

    $webpage->click('[data-bulk-action="monitor"]')
        ->assertSee('1 started');

    expect(ActionRequest::query()->where('type', 'monitor_series')->pluck('payload')->all())->toEqual([
        ['series_id' => 1, 'monitored' => true, 'service_connection_id' => $this->sonarr->id],
    ]);
    expect($state->calls)->toBe(2);
});

test('a movie that drops out of the library during a reload drops out of the bulk selection too', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    $full = [];

    foreach ([10 => 'Dune', 11 => 'Arrival', 12 => 'Nope'] as $id => $title) {
        $full[] = ['id' => $id, 'title' => $title, 'titleSlug' => strtolower($title), 'year' => 2021, 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => bulkBrowserPoster()];
        IndexedMovie::factory()->for($radarr, 'serviceConnection')->create(['radarr_id' => $id, 'title' => $title, 'year' => 2021]);
    }

    // Arrival (11) is gone from Radarr's own list by the next reload — same
    // single-registration, stateful-closure fake as the Sonarr test above.
    $state = new class
    {
        public int $calls = 0;
    };
    Http::fake([
        'radarr.local:7878/api/v3/movie' => function () use ($state, $full) {
            $state->calls++;

            return Http::response($state->calls === 1 ? $full : [$full[0], $full[2]]);
        },
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p'], ['id' => 6, 'name' => 'Ultra-HD']]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.movies.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-movie-card="10"]', 'Dune')
        ->click('[data-bulk-select="10"]')
        ->click('[data-bulk-select="11"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected');

    new RadarrCache($radarr)->bustAll();

    $webpage->click('Sync')
        ->assertSeeIn('[data-movie-card="10"]', 'Dune')
        ->assertCount('[data-movie-card="11"]', 0)
        ->assertCount('[data-bulk-select="11"]', 0)
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->assertNoSmoke();

    $webpage->click('[data-bulk-action="monitor"]')
        ->assertSee('1 started');

    expect(ActionRequest::query()->where('type', 'monitor_movie')->pluck('payload')->all())->toEqual([
        ['movie_id' => 10, 'monitored' => true, 'service_connection_id' => $radarr->id],
    ]);
    expect($state->calls)->toBe(2);
});

test('a running bulk action freezes Clear and the checkboxes, then hands focus to select all', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 3);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select="1"]')
        ->click('[data-bulk-select="3"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected');

    $webpage->script(holdBulkRequestScript());
    $webpage->click('[data-bulk-action="monitor"]');

    $webpage->assertScript("document.querySelector('[data-bulk-clear]').disabled === true")
        ->assertScript("document.querySelector('[data-bulk-select=\"2\"] button').disabled === true")
        ->assertScript("document.querySelector('[data-bulk-select-all] button').disabled === true")
        ->assertScript("document.querySelector('[data-bulk-bar]').getAttribute('role') === 'region'")
        ->assertScript("document.querySelector('[data-bulk-count]').getAttribute('aria-live') === 'polite'");

    $webpage->script('window.__releaseBulk()');
    $webpage->assertSee('2 started')
        ->assertCount('[data-bulk-bar]', 0);

    $webpage->script(bulkBrowserWaitUntil("document.activeElement && document.activeElement.closest('[data-bulk-select-all]')"));
    $webpage->assertScript("document.activeElement !== null && document.activeElement.closest('[data-bulk-select-all]') !== null")
        ->assertNoSmoke();
});

test('the over-limit message describes the disabled actions', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 101);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select-all]')
        ->assertPresent('[data-bulk-over-limit]')
        ->assertScript("document.querySelector('[data-bulk-actions]').getAttribute('aria-describedby') === document.querySelector('[data-bulk-over-limit]').id");
});

test('exactly 25 selected searches without asking first', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 25);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select-all]')
        ->assertSeeIn('[data-bulk-count]', '25 selected')
        ->click('[data-bulk-action="search"]')
        ->assertSee('25 started')
        ->assertCount('[data-bulk-search-warning]', 0);

    expect(ActionRequest::query()->where('type', 'search_media')->count())->toBe(25);
});

test('cancelling the bulk delete files nothing, and reopening starts with delete-files unticked', function (): void {
    fakeBulkSonarrLibrary($this->sonarr, 3);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-bulk-select="1"]')
        ->click('[data-bulk-select="2"]')
        ->click('[data-bulk-action="delete"]')
        ->assertSeeIn('[data-bulk-delete-description]', '2 series')
        ->click('[data-bulk-delete-files]')
        ->assertAttribute('[data-bulk-delete-files]', 'data-state', 'checked')
        ->click('[data-bulk-delete-cancel]')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->click('[data-bulk-action="delete"]')
        ->assertSeeIn('[data-bulk-delete-description]', '2 series')
        ->assertAttribute('[data-bulk-delete-files]', 'data-state', 'unchecked')
        ->assertNoSmoke();

    expect(ActionRequest::query()->count())->toBe(0);
});

test('a selected series that leaves the active filter on reload drops out of the selection', function (): void {
    $series = static fn (int $id, bool $monitored): array => [
        'id' => $id, 'title' => sprintf('Series %03d', $id), 'titleSlug' => sprintf('series-%d', $id), 'year' => 2020, 'status' => 'continuing',
        'monitored' => $monitored, 'qualityProfileId' => 1, 'images' => bulkBrowserPoster(), 'seasons' => [],
        'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
    ];
    $state = new class
    {
        public int $calls = 0;
    };
    // Series 003 is monitored in Sonarr by the next reload, so it leaves the
    // "Unmonitored" filter the user is looking at.
    Http::fake([
        'sonarr.local:8989/api/v3/series' => function () use ($state, $series) {
            $state->calls++;

            return Http::response([$series(1, false), $series(2, true), $series(3, $state->calls > 1)]);
        },
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
    foreach ([1, 2, 3] as $id) {
        IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => $id, 'title' => sprintf('Series %03d', $id), 'year' => 2020]);
    }

    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Series 001')
        ->click('[data-monitored-filter="unmonitored"]')
        ->click('[data-bulk-select="1"]')
        ->click('[data-bulk-select="3"]')
        ->assertSeeIn('[data-bulk-count]', '2 selected');

    new SonarrCache($this->sonarr)->bustAll();

    $webpage->click('Sync')
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->assertCount('[data-series-card="3"]', 0);

    $webpage->click('[data-bulk-action="monitor"]')
        ->assertSee('1 started');

    expect(ActionRequest::query()->where('type', 'monitor_series')->get()->pluck('payload.series_id')->all())->toBe([1]);
});
