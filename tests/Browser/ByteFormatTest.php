<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * One Sonarr series per size, all on one fake Sonarr.
 *
 * @param  list<int>  $sizes
 */
function byteFormatFakeSonarr(array $sizes): void
{
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    $series = [];

    foreach ($sizes as $index => $size) {
        $id = $index + 1;
        $series[] = [
            'id' => $id, 'title' => sprintf('Series %d', $id), 'titleSlug' => sprintf('series-%d', $id), 'year' => 2020, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [], 'seasons' => [],
            'statistics' => ['sizeOnDisk' => $size, 'episodeCount' => 1, 'episodeFileCount' => 1],
        ];
    }

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response($series),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
}

/**
 * One Radarr movie per size, all on one fake Radarr.
 *
 * @param  list<int>  $sizes
 */
function byteFormatFakeRadarr(array $sizes): void
{
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    $movies = [];

    foreach ($sizes as $index => $size) {
        $id = $index + 1;
        $movies[] = [
            'id' => $id, 'title' => sprintf('Movie %d', $id), 'titleSlug' => sprintf('movie-%d', $id), 'year' => 2021,
            'monitored' => true, 'hasFile' => $size > 0, 'qualityProfileId' => 1, 'sizeOnDisk' => $size, 'images' => [],
        ];
    }

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response($movies),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
}

test('the Sonarr library total reads in binary gigabytes with one decimal', function (): void {
    byteFormatFakeSonarr([1_610_612_736, 0]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-library-total-size]', '1.5 GB');
});

test('a size just under a unit boundary moves up a unit instead of printing 1024', function (): void {
    byteFormatFakeSonarr([1_073_741_823]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-library-total-size]', '1.0 GB')
        ->assertDontSeeIn('[data-library-total-size]', '1024');
});

test('the Radarr library total reads terabytes with one decimal and each card shows its own size', function (): void {
    byteFormatFakeRadarr([1_099_511_627_776, 1_099_511_627_776]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.movies.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-library-total-size]', '2.0 TB')
        ->assertSeeIn('[data-movie-card="1"]', '1.0 TB');
});

test('a library with nothing on disk totals zero bytes rather than a dash', function (): void {
    byteFormatFakeRadarr([0]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.movies.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-library-total-size]', '0 B')
        ->assertSeeIn('[data-movie-card="1"]', '0 B');
});
