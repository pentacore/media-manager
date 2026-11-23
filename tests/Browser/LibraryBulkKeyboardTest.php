<?php

declare(strict_types=1);

use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * The library grid's bulk checkbox sits beside the card link, after it in
 * the tab order: Tab from a card lands on its checkbox, and Space ticks it
 * without opening the title.
 */

/**
 * Sonarr with $count series (ids 1..$count) whose posters load, plus the
 * detail read a focused card's prefetch may make.
 */
function libraryBulkKeyboardFakes(ServiceConnection $sonarr, int $count): void
{
    $poster = [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
    $series = [];

    foreach (range(1, $count) as $number) {
        $title = sprintf('Keyboard Series %03d', $number);
        $series[] = [
            'id' => $number, 'title' => $title, 'titleSlug' => sprintf('keyboard-series-%d', $number), 'year' => 2020, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => $poster, 'seasons' => [],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
        ];
        IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create(['sonarr_id' => $number, 'title' => $title, 'year' => 2020]);
    }

    Http::fake([
        'sonarr.local:8989/api/v3/series/*' => Http::response($series[0]),
        'sonarr.local:8989/api/v3/series' => Http::response($series),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
}

/**
 * The checkbox control inside series $id's bulk tick.
 */
function libraryBulkKeyboardCheckbox(int $id): string
{
    return sprintf('[data-bulk-select="%d"] [role="checkbox"]', $id);
}

test('Tab from a series card reaches its checkbox and Space ticks and unticks it', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    libraryBulkKeyboardFakes($sonarr, 3);
    $this->actingAs(User::factory()->member()->create());
    $seriesIndexPath = route('media.series.index', absolute: false);

    $webpage = visit($seriesIndexPath)
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="2"]', 'Keyboard Series 002');
    $webpage->script(appHydratedScript());

    $webpage->keys('[data-series-card="2"]', 'Tab')
        ->assertScript(sprintf("document.activeElement === document.querySelector('%s')", libraryBulkKeyboardCheckbox(2)), true)
        ->keys(libraryBulkKeyboardCheckbox(2), 'Space')
        ->assertAttribute(libraryBulkKeyboardCheckbox(2), 'aria-checked', 'true')
        ->assertAttribute(libraryBulkKeyboardCheckbox(1), 'aria-checked', 'false')
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->assertPathIs($seriesIndexPath)
        ->keys(libraryBulkKeyboardCheckbox(3), 'Space')
        ->assertSeeIn('[data-bulk-count]', '2 selected')
        ->keys(libraryBulkKeyboardCheckbox(2), 'Space')
        ->assertAttribute(libraryBulkKeyboardCheckbox(2), 'aria-checked', 'false')
        ->assertSeeIn('[data-bulk-count]', '1 selected')
        ->keys(libraryBulkKeyboardCheckbox(3), 'Space')
        ->assertCount('[data-bulk-bar]', 0)
        ->assertPathIs($seriesIndexPath)
        ->assertNoSmoke();
});

test('Enter on a focused checkbox neither ticks it nor opens the title', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    libraryBulkKeyboardFakes($sonarr, 2);
    $this->actingAs(User::factory()->member()->create());
    $seriesIndexPath = route('media.series.index', absolute: false);

    $webpage = visit($seriesIndexPath)
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Keyboard Series 001');
    $webpage->script(appHydratedScript());

    $webpage->keys(libraryBulkKeyboardCheckbox(1), 'Enter');
    $webpage->wait(0.3);

    $webpage->assertAttribute(libraryBulkKeyboardCheckbox(1), 'aria-checked', 'false')
        ->assertCount('[data-bulk-bar]', 0)
        ->assertPathIs($seriesIndexPath)
        ->assertNoSmoke();
});
