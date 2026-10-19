<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Series 7 with one season and one episode.
 */
function seriesSectionsFakeSonarr(): void
{
    $episodes = [
        ['id' => 70, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Example Pilot Episode', 'airDate' => '2026-02-18', 'hasFile' => false, 'monitored' => true],
    ];

    Http::fake([
        'sonarr.local:8989/api/v3/series/7*' => Http::response([
            'id' => 7, 'title' => 'Example Series Title', 'titleSlug' => 'example-series-title', 'year' => 2026, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [],
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 0, 'sizeOnDisk' => 0]]],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 1, 'episodeFileCount' => 0],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response($episodes),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/release*' => Http::response([]),
    ]);
}

/**
 * Fakes the series list Sonarr reads for the library index, with series 7 as
 * its only row, so a member can reach the show page through a real `<Link>`
 * (an SPA visit) rather than a fresh full navigation.
 */
function seriesSectionsFakeSonarrIndexList(): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([[
            'id' => 7, 'title' => 'Example Series Title', 'titleSlug' => 'example-series-title', 'year' => 2026, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [],
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 0, 'sizeOnDisk' => 0]]],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 1, 'episodeFileCount' => 0],
        ]]),
    ]);
}

/**
 * Holds back the answer to the show page's deferred `episodes` reload, as a
 * slow Sonarr read would: the request is sent and answered at once, but the
 * page only sees the answer once window.__mmReleaseHeld() runs. Installed
 * while still on the index page (before the SPA visit that mounts the show
 * page), because the show page's own reload fires during that visit's mount,
 * too early for a script run after it to catch.
 */
function seriesSectionsHoldEpisodesScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmHeldAnswered = false;
            window.__mmHeldQueue = [];
            window.__mmReleaseHeld = () => {
                const queue = window.__mmHeldQueue.splice(0);
                queue.forEach((release) => release());
            };
            const setRequestHeader = XMLHttpRequest.prototype.setRequestHeader;
            XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                if (String(name).toLowerCase() === 'x-inertia-partial-data') {
                    this.__mmPartialData = String(value).split(',');
                }
                return setRequestHeader.call(this, name, value);
            };
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (body) {
                if ((this.__mmPartialData ?? []).includes('episodes')) {
                    const onload = this.onload;
                    this.onload = (...args) => {
                        window.__mmHeldQueue.push(() => { if (onload) { onload.apply(this, args); } });
                        window.__mmHeldAnswered = true;
                    };
                }
                return send.call(this, body);
            };
        })()
        JS;
}

/**
 * Waits until the held queue holds the `episodes` request. `<Deferred>`
 * sends exactly one reload for it (unlike the `<WhenVisible>` it replaced,
 * which fired a second one independently), so polling the queue itself —
 * rather than a fixed sleep for "the second one to join it" — is both
 * faster and self-checking: a regression that reintroduced a second request
 * would leave the count at 2 and the wait would time out instead of
 * silently releasing both together.
 */
function seriesSectionsWaitForHeldEpisodesScript(): string
{
    return confirmDialogWaitUntilScript('window.__mmHeldQueue.length === 1');
}

/**
 * Records every partial reload that finishes, as its comma-joined `only`.
 */
function seriesSectionsReloadRecorderScript(): string
{
    return <<<'JS'
        (() => {
            window.__seriesSectionsReloads = [];
            document.addEventListener('inertia:finish', (event) => {
                const visit = event.detail.visit;
                if (!visit.prefetch && visit.only.length > 0) {
                    window.__seriesSectionsReloads.push(visit.only.join(','));
                }
            });
        })()
    JS;
}

beforeEach(function (): void {
    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
});

test('an expanded season stays open when a processed webhook reloads the series', function (): void {
    seriesSectionsFakeSonarr();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-season-card="1"] button')
        ->assertVisible('[data-season-card="1"] [data-episode-actions="70"]');
    $webpage->script(seriesSectionsReloadRecorderScript());

    $webpage->script(echoEmitScript('dashboard', 'WebhookEventProcessed', [
        'id' => 7001, 'service_connection_id' => $this->sonarr->id, 'service_type' => 'sonarr',
        'event_type' => 'Download', 'processed_at' => '2026-10-12T10:00:00.000000Z',
    ]));
    $webpage->script(confirmDialogWaitUntilScript("window.__seriesSectionsReloads.includes('series,episodes')"));

    expect($webpage->script("window.__seriesSectionsReloads.filter((only) => only === 'series,episodes').length"))->toBe(1);
    $webpage->assertVisible('[data-season-card="1"] [data-episode-actions="70"]')
        ->assertNoSmoke();
});

test('the seasons show skeleton rows until the episodes arrive', function (): void {
    seriesSectionsFakeSonarr();
    seriesSectionsFakeSonarrIndexList();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="7"]', 'Example Series Title');
    $webpage->script(appHydratedScript());
    $webpage->script(seriesSectionsHoldEpisodesScript());

    $webpage->click('[data-series-card="7"]');
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-season-skeleton=\"1\"]') !== null"));

    $webpage->assertPresent('[data-season-skeleton="1"]')
        ->assertMissing('[data-season-card="1"]');

    $webpage->script(seriesSectionsWaitForHeldEpisodesScript());

    $webpage->assertPresent('[data-season-skeleton="1"]')
        ->assertMissing('[data-season-card="1"]');

    $webpage->script('window.__mmReleaseHeld()');
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-season-card=\"1\"]') !== null"));

    $webpage->assertPresent('[data-season-card="1"]')
        ->assertMissing('[data-season-skeleton="1"]')
        ->click('[data-season-card="1"] button')
        ->assertSeeIn('[data-season-card="1"]', 'Example Pilot Episode')
        ->assertNoSmoke();
});

test('the show page requests episodes only once per view', function (): void {
    // Pins the fix for the pre-existing bug where Inertia's automatic
    // deferred-group loader and the section's own <WhenVisible> each
    // reloaded `episodes` independently, so a view fired two requests for
    // it; the section now uses <Deferred>, which has no reload of its own.
    seriesSectionsFakeSonarr();
    seriesSectionsFakeSonarrIndexList();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="7"]', 'Example Series Title');
    $webpage->script(appHydratedScript());
    $webpage->script(seriesSectionsReloadRecorderScript());

    $webpage->click('[data-series-card="7"]');
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-season-card=\"1\"]') !== null"));

    $webpage->assertPresent('[data-season-card="1"]')
        ->assertNoSmoke();

    expect($webpage->script("window.__seriesSectionsReloads.filter((only) => only === 'episodes').length"))->toBe(1);
});

test("an episode's interactive search names the episode in its heading", function (): void {
    seriesSectionsFakeSonarr();
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertNoSmoke()
        ->click('[data-season-card="1"] button')
        ->click('[data-episode-actions="70"] [data-interactive-search]')
        ->assertSeeIn('[data-interactive-search-dialog]', 'Interactive search — S01E01 Example Pilot Episode')
        ->assertNoSmoke();
});
