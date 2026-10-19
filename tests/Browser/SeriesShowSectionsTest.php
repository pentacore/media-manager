<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Series 7 with one season and one episode. $slowEpisodes holds the episode
 * read for 1.5 s, so the page's deferred episode load is still running.
 */
function seriesSectionsFakeSonarr(bool $slowEpisodes = false): void
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
        'sonarr.local:8989/api/v3/episode*' => $slowEpisodes
            ? function () use ($episodes) {
                Sleep::usleep(1_500_000);

                return Http::response($episodes);
            }
            : Http::response($episodes),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/release*' => Http::response([]),
    ]);
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
    seriesSectionsFakeSonarr(slowEpisodes: true);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))
        ->assertPresent('[data-season-skeleton="1"]')
        ->assertMissing('[data-season-card="1"]');
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-season-card=\"1\"]') !== null"));

    $webpage->assertPresent('[data-season-card="1"]')
        ->assertMissing('[data-season-skeleton="1"]')
        ->click('[data-season-card="1"] button')
        ->assertSeeIn('[data-season-card="1"]', 'Example Pilot Episode')
        ->assertNoSmoke();
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
