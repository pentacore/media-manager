<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Jobs\ExecuteActionRequest;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;

/**
 * @return list<array{coverType: string, remoteUrl: string}>
 */
function libraryBulkBarPoster(): array
{
    return [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
}

/**
 * @return array<string, mixed>
 */
function libraryBulkBarSeries(int $id): array
{
    return [
        'id' => $id, 'title' => sprintf('Example Series %02d', $id), 'titleSlug' => sprintf('example-series-%d', $id), 'year' => 2020, 'status' => 'continuing',
        'monitored' => true, 'qualityProfileId' => 1, 'images' => libraryBulkBarPoster(), 'seasons' => [],
        'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
    ];
}

/**
 * Sonarr (24 series), Radarr (12 movies) and Whisparr V3 (12 scenes), each
 * with two quality profiles, so the series page is long enough to scroll
 * past the bar and every library bar renders its quality-profile Select.
 */
function libraryBulkBarFakes(): void
{
    $profiles = [['id' => 1, 'name' => 'HD-1080p'], ['id' => 2, 'name' => 'Example Ultra-HD Remux 2160p With A Long Profile Name']];
    $series = array_map(libraryBulkBarSeries(...), range(1, 24));
    $movies = array_map(static fn (int $id): array => [
        'id' => $id, 'title' => sprintf('Example Movie %02d', $id), 'titleSlug' => sprintf('example-movie-%d', $id), 'year' => 2021,
        'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => libraryBulkBarPoster(),
    ], range(10, 21));
    $scenes = array_map(static fn (int $id): array => [
        'id' => $id, 'title' => sprintf('Example Scene %03d', $id), 'year' => 2024,
        'monitored' => true, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => libraryBulkBarPoster(),
    ], range(101, 112));

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response($series),
        'sonarr.local:8989/api/v3/series/2*' => Http::response(libraryBulkBarSeries(2)),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response($profiles),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/movie' => Http::response($movies),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response($profiles),
        'radarr.local:7878/*' => Http::response([]),
        'whisparr.local:6969/api/v3/movie' => Http::response($scenes),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response($profiles),
        'whisparr.local:6969/*' => Http::response([]),
    ]);

    // After the fakes: creating a connection reads its library, and an
    // unfaked .local host stalls on DNS.
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
}

/**
 * Counts the rows the bar's visible controls sit on: a control starts a new
 * row when its top is below the bottom of everything placed so far.
 */
function libraryBulkBarRowsScript(): string
{
    return <<<'JS'
        (() => {
            const rects = [...document.querySelectorAll('[data-bulk-bar] [data-bulk-count], [data-bulk-bar] button')]
                .filter((control) => control.getClientRects().length > 0)
                .map((control) => control.getBoundingClientRect())
                .sort((first, second) => first.top - second.top);
            let rows = 0;
            let bottom = -Infinity;
            for (const rect of rects) {
                if (rect.top >= bottom - 1) {
                    rows++;
                    bottom = rect.bottom;
                } else {
                    bottom = Math.max(bottom, rect.bottom);
                }
            }

            return rows;
        })()
    JS;
}

/**
 * Ticks the card's checkbox (only the active view renders, so the hook is
 * unique) and waits until the bar has its quality-profile Select and has
 * published its measured height: the ResizeObserver writes the variable a
 * frame after the bar mounts, and the bar grows when the Select renders.
 */
function libraryBulkBarPick(AwaitableWebpage|PendingAwaitablePage|Webpage $webpage, int $id): void
{
    $webpage->click(sprintf('[data-bulk-select="%d"]', $id));
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-bulk-bar] [data-bulk-quality-profile-trigger]") && document.body.style.getPropertyValue("--bulk-bar-height") !== ""'));
    $webpage->assertPresent('[data-bulk-bar]');
}

beforeEach(function (): void {
    // The quality-profile pick sends a real bulk request: queue the actions
    // without running them, so no upstream write is attempted.
    $this->seed(ActionTypeConfigSeeder::class);
    Queue::fake([ExecuteActionRequest::class]);
});

test('the library bar is fixed to the bottom of a phone screen as soon as a card is picked', function (string $routeName, int $id, string $role): void {
    libraryBulkBarFakes();
    $this->actingAs(User::factory()->{$role}()->create());

    $webpage = visit(route($routeName, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    libraryBulkBarPick($webpage, $id);

    $webpage->assertScript('getComputedStyle(document.querySelector("[data-bulk-bar]")).position', 'fixed')
        ->assertScript('Math.abs(document.querySelector("[data-bulk-bar]").getBoundingClientRect().bottom - window.innerHeight) <= 1', true)
        ->assertScript(phoneOverflowScript('[data-bulk-bar]'), '')
        ->assertScript(phoneSmallTargetsScript('[data-bulk-bar]'), '');
})->with([
    'series' => ['media.series.index', 1, 'member'],
    'movies' => ['media.movies.index', 10, 'member'],
    'whisparr' => ['media.whisparr.index', 101, 'admin'],
]);

test('the page ends with room for the whole library bar', function (): void {
    libraryBulkBarFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    libraryBulkBarPick($webpage, 1);

    $heightMatches = 'Math.round(parseFloat(document.body.style.getPropertyValue("--bulk-bar-height"))) === Math.round(document.querySelector("[data-bulk-bar]").getBoundingClientRect().height)';
    $webpage->script(confirmDialogWaitUntilScript($heightMatches));

    $webpage->assertScript($heightMatches, true)
        ->assertScript('(() => { window.scrollTo({ top: document.documentElement.scrollHeight, behavior: "instant" }); const cards = [...document.querySelectorAll("[data-series-card]")]; return cards.at(-1).getBoundingClientRect().bottom <= document.querySelector("[data-bulk-bar]").getBoundingClientRect().top; })()', true);
});

test('the library bar takes at most three rows of a phone screen with every action shown', function (string $routeName, int $id, string $role): void {
    libraryBulkBarFakes();
    $this->actingAs(User::factory()->{$role}()->create());

    $webpage = visit(route($routeName, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    libraryBulkBarPick($webpage, $id);

    $webpage->assertCount('[data-bulk-bar] [data-bulk-action]', 4)
        ->assertPresent('[data-bulk-bar] [data-bulk-quality-profile-trigger]')
        ->assertScript('[...document.querySelectorAll("[data-bulk-bar] [data-bulk-action], [data-bulk-bar] [data-bulk-quality-profile-trigger], [data-bulk-bar] [data-bulk-clear]")].every((control) => { const rect = control.getBoundingClientRect(); return rect.width > 0 && rect.left >= 0 && rect.right <= window.innerWidth && rect.bottom <= window.innerHeight; })', true)
        ->assertScript(libraryBulkBarRowsScript().' <= 3', true);
})->with([
    'series' => ['media.series.index', 1, 'member'],
    'movies' => ['media.movies.index', 10, 'member'],
    'whisparr' => ['media.whisparr.index', 101, 'admin'],
]);

test('clearing the library selection drops the measured spacer', function (): void {
    libraryBulkBarFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    libraryBulkBarPick($webpage, 1);
    $webpage->click('[data-bulk-clear]');

    $webpage->assertCount('[data-bulk-bar]', 0)
        ->assertScript('document.body.dataset.bulkBarOpen === undefined && document.body.style.getPropertyValue("--bulk-bar-height") === ""', true)
        ->assertScript('getComputedStyle(document.querySelector("main[data-slot=\"sidebar-inset\"]"), "::after").content', 'none');
});

test('the quality profile list opens fully on screen from the fixed bar', function (): void {
    libraryBulkBarFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    libraryBulkBarPick($webpage, 1);
    $webpage->click('[data-bulk-quality-profile-trigger]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[role=\"listbox\"]") && document.querySelector("[role=\"listbox\"]").getAnimations().length === 0'));

    $webpage->assertScript('(() => { const rect = document.querySelector("[role=\"listbox\"]").getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= window.innerHeight && rect.left >= 0 && rect.right <= window.innerWidth; })()', true);
    $webpage->click('[data-bulk-quality-profile-option="2"]');
    $webpage->assertNoSmoke();
});

test("leaving the series page with a selection drops the bar's spacer", function (): void {
    libraryBulkBarFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    libraryBulkBarPick($webpage, 1);
    $webpage->click('[data-series-card="2"]');
    $webpage->assertPathIs(route('media.series.show', ['id' => 2], absolute: false));

    $webpage->assertScript('document.body.dataset.bulkBarOpen === undefined && document.body.style.getPropertyValue("--bulk-bar-height") === ""', true)
        ->assertScript('getComputedStyle(document.querySelector("main[data-slot=\"sidebar-inset\"]"), "::after").content', 'none');
});

test('the library bar is fixed on a phone and sticky again at 800 px', function (): void {
    libraryBulkBarFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    libraryBulkBarPick($webpage, 1);
    $webpage->resize(800, 1000);

    $webpage->assertScript('getComputedStyle(document.querySelector("[data-bulk-bar]")).position', 'sticky')
        ->assertScript('getComputedStyle(document.querySelector("main[data-slot=\"sidebar-inset\"]"), "::after").content', 'none');
});

test('the library bar stays a sticky card on desktop', function (): void {
    libraryBulkBarFakes();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    libraryBulkBarPick($webpage, 1);

    $webpage->assertScript('getComputedStyle(document.querySelector("[data-bulk-bar]")).position', 'sticky')
        ->assertScript('getComputedStyle(document.querySelector("[data-bulk-bar]")).bottom', '16px');
});
