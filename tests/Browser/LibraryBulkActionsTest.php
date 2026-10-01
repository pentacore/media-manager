<?php

declare(strict_types=1);

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
