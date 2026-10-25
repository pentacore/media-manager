<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;

function detailToolbarPhoneFakes(): void
{
    $poster = [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series/7*' => Http::response([
            'id' => 7, 'title' => 'Severance', 'titleSlug' => 'severance', 'year' => 2022, 'status' => 'continuing', 'network' => 'Example Network',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => $poster, 'overview' => 'An example overview.',
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 1, 'totalEpisodeCount' => 1, 'sizeOnDisk' => 2_000_000_000]]],
            'statistics' => ['sizeOnDisk' => 2_000_000_000, 'episodeCount' => 1, 'episodeFileCount' => 1],
        ]),
        'sonarr.local:8989/api/v3/episodefile*' => Http::response([]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 71, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Good News About Hell, An Example Episode Title', 'airDate' => '2022-02-18', 'hasFile' => true, 'monitored' => true, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p'], ['id' => 2, 'name' => 'Ultra-HD']]),
        'sonarr.local:8989/api/v3/release*' => Http::response([]),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/movie/10*' => Http::response([
            'id' => 10, 'title' => 'Dune', 'titleSlug' => 'dune-2021', 'year' => 2021, 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1,
            'sizeOnDisk' => 12_000_000_000, 'images' => $poster, 'overview' => 'An example overview.', 'runtime' => 155, 'status' => 'released',
            'movieFile' => ['id' => 10, 'relativePath' => 'Dune.2021.Bluray-1080p.mkv', 'size' => 12_000_000_000, 'quality' => ['quality' => ['name' => 'Bluray-1080p']]],
        ]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/release*' => Http::response([]),
        'radarr.local:7878/*' => Http::response([]),
    ]);
}

beforeEach(function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    detailToolbarPhoneFakes();
});

test('the movie toolbar keeps Monitor and Search on a phone and moves the rest into its menu', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.movies.show', ['id' => 10], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertVisible('[data-movie-back]')
        ->assertVisible('[data-movie-actions] [data-monitor-toggle]')
        ->assertVisible('[data-movie-actions] [data-search-now]')
        ->assertMissing('[data-interactive-search]')
        ->assertMissing('[data-movie-open-radarr]')
        ->assertMissing('[data-delete-trigger]')
        ->assertScript(phoneOverflowScript('main'), '')
        ->click('[data-page-header-more]')
        ->assertVisible('[data-page-header-menu] [data-page-header-item="replace"]')
        ->assertAttribute('[data-page-header-item="open-radarr"]', 'href', 'http://radarr.local:7878/movie/dune-2021')
        ->assertAttribute('[data-page-header-item="open-radarr"]', 'target', '_blank')
        ->click('[data-page-header-item="interactive-search"]')
        ->assertVisible('[data-interactive-search-dialog]');

    $webpage->keys('[data-interactive-search-dialog]', 'Escape');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-interactive-search-dialog]")'));
    $webpage->click('[data-page-header-more]')
        ->click('[data-page-header-item="delete"]')
        ->assertSeeIn('[data-delete-description]', 'Removes the movie from Radarr.')
        ->assertNoSmoke();
});

test('the series toolbar keeps Monitor and Search on a phone and moves Open in Sonarr and Delete into its menu', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertVisible('[data-series-back]')
        ->assertVisible('[data-series-actions] [data-monitor-toggle]')
        ->assertVisible('[data-series-actions] [data-search-now]')
        ->assertMissing('[data-series-open-sonarr]')
        ->assertScript(phoneOverflowScript('main'), '')
        ->click('[data-page-header-more]')
        ->assertAttribute('[data-page-header-item="open-sonarr"]', 'target', '_blank')
        ->click('[data-page-header-item="delete"]')
        ->assertSeeIn('[data-delete-description]', 'Removes the series from Sonarr.')
        ->assertNoSmoke();
});

test("an episode row's Interactive search and Replace stay reachable on a phone", function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('Season 1');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-episode-actions=\"71\"]")'));

    $webpage->assertScript(phoneOverflowScript('main'), '')
        ->assertVisible('[data-episode-actions="71"] [data-interactive-search]')
        ->click('[data-episode-actions="71"] [data-interactive-search]')
        ->assertVisible('[data-interactive-search-dialog]');
});

test('a viewer sees the detail toolbars with no actions and no menu on a phone', function (): void {
    $this->actingAs(User::factory()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->assertVisible('[data-series-back]')
        ->assertCount('[data-series-actions]', 0)
        ->assertCount('[data-page-header-more]', 0);

    $webpage->navigate(route('media.movies.show', ['id' => 10], absolute: false));
    $webpage->assertVisible('[data-movie-back]')
        ->assertCount('[data-movie-actions]', 0)
        ->assertCount('[data-page-header-more]', 0);
});

test('at desktop width the detail toolbars keep every action inline', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.movies.show', ['id' => 10], absolute: false))->assertNoSmoke();
    $webpage->assertVisible('[data-interactive-search]')
        ->assertVisible('[data-movie-open-radarr]')
        ->assertVisible('[data-replacement-trigger]')
        ->assertVisible('[data-delete-trigger]')
        ->assertMissing('[data-page-header-more]');

    $webpage->navigate(route('media.series.show', ['id' => 7], absolute: false));
    $webpage->assertVisible('[data-series-open-sonarr]')
        ->assertVisible('[data-delete-trigger]')
        ->assertMissing('[data-page-header-more]');
});
