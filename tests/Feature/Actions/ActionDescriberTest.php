<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\UndescribableAction;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function describerSeries(): void
{
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'name' => 'Sonarr']);
    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance', 'year' => 2022]);
}

test('delete_series names the series and says files will be deleted', function (): void {
    describerSeries();

    $description = resolve(ActionDescriber::class)->describe('delete_series', ['sonarr_series_id' => 142, 'delete_files' => true]);

    expect($description->title)->toBe('Delete series "Severance (2022)"')
        ->and($description->description)->toBe('Sonarr will delete the series and its files from disk.')
        ->and($description->details)->toContain(['label' => 'Delete files', 'value' => 'Yes'])
        ->and($description->verified)->toBeTrue();
});

test('delete_movie without deleting files says files are kept', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    IndexedMovie::factory()->for($radarr, 'serviceConnection')->create(['radarr_id' => 55, 'title' => 'Dune', 'year' => 2021]);

    $description = resolve(ActionDescriber::class)->describe('delete_movie', ['radarr_movie_id' => 55, 'delete_files' => false]);

    expect($description->title)->toBe('Delete movie "Dune (2021)"')
        ->and($description->description)->toBe('Radarr will remove the movie but keep its files on disk.')
        ->and($description->details)->toContain(['label' => 'Delete files', 'value' => 'No']);
});

test('add_series lists the quality profile name, root folder and monitoring', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([['title' => 'Shogun', 'year' => 2024]]),
        'sonarr.local:8989/api/v3/qualityprofile*' => Http::response([['id' => 4, 'name' => 'HD-1080p']]),
    ]);

    $description = resolve(ActionDescriber::class)->describe('add_series', [
        'tvdb_id' => 999, 'quality_profile_id' => 4, 'root_folder_path' => '/tv', 'monitored' => true, 'season_folder' => true,
    ]);

    expect($description->title)->toBe('Add series "Shogun (2024)"')
        ->and($description->description)->toBe('Sonarr will add the series and search for it.')
        ->and($description->details)->toContain(['label' => 'Quality profile', 'value' => 'HD-1080p'])
        ->and($description->details)->toContain(['label' => 'Root folder', 'value' => '/tv'])
        ->and($description->details)->toContain(['label' => 'Monitored', 'value' => 'Yes'])
        ->and($description->details)->toContain(['label' => 'Season folders', 'value' => 'Yes']);
});

test('monitor_series words starting and stopping monitoring', function (bool $monitored, string $title, string $effect): void {
    describerSeries();

    $description = resolve(ActionDescriber::class)->describe('monitor_series', ['series_id' => 142, 'monitored' => $monitored]);

    expect($description->title)->toBe($title)->and($description->description)->toBe($effect);
})->with([
    'monitor' => [true, 'Monitor series "Severance (2022)"', 'Sonarr will start monitoring the series.'],
    'unmonitor' => [false, 'Unmonitor series "Severance (2022)"', 'Sonarr will stop monitoring the series.'],
]);

test('set_series_quality_profile names the new profile', function (): void {
    describerSeries();
    Http::fake(['sonarr.local:8989/api/v3/qualityprofile*' => Http::response([['id' => 6, 'name' => 'Ultra-HD']])]);

    $description = resolve(ActionDescriber::class)->describe('set_series_quality_profile', ['series_id' => 142, 'quality_profile_id' => 6]);

    expect($description->title)->toBe('Change quality profile of series "Severance (2022)"')
        ->and($description->description)->toBe('Sonarr will switch the series to the "Ultra-HD" quality profile.');
});

test('seerr request types name the requested media', function (string $type, string $title): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055']);
    Http::fake([
        'seerr.local:5055/api/v1/request/12' => Http::response(['type' => 'tv', 'media' => ['tmdbId' => 95396], 'requestedBy' => ['displayName' => 'Alex']]),
        'seerr.local:5055/api/v1/tv/95396' => Http::response(['name' => 'Severance', 'firstAirDate' => '2022-02-18']),
    ]);

    $description = resolve(ActionDescriber::class)->describe($type, ['seerr_request_id' => 12]);

    expect($description->title)->toBe($title)
        ->and($description->details)->toContain(['label' => 'Requested by', 'value' => 'Alex']);
})->with([
    ['approve_seerr_request', 'Approve Seerr request for "Severance (2022)"'],
    ['decline_seerr_request', 'Decline Seerr request for "Severance (2022)"'],
    ['cleanup_seerr_request', 'Clean up Seerr request for "Severance (2022)"'],
]);

test('emby_library_scan names the emby server', function (): void {
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);

    $description = resolve(ActionDescriber::class)->describe('emby_library_scan', []);

    expect($description->title)->toBe('Scan the Emby library')
        ->and($description->description)->toBe('Emby server "Living Room" will rescan its libraries to pick up changes.')
        ->and($description->verified)->toBeTrue();
});

test('remove_stuck_download describes blocklisting and searching', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake(['sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
        ['downloadId' => 'ABC', 'title' => 'Bad.Release.1080p'],
    ]])]);

    $description = resolve(ActionDescriber::class)->describe('remove_stuck_download', [
        'service' => 'sonarr', 'download_id' => 'ABC', 'blocklist' => true, 'search_replacement' => true,
    ]);

    expect($description->title)->toBe('Remove stuck download "Bad.Release.1080p"')
        ->and($description->description)->toBe('Sonarr will remove the download from its queue and delete its data, blocklist the release, then search for a replacement.')
        ->and($description->details)->toContain(['label' => 'Blocklist release', 'value' => 'Yes']);
});

test('resolve_manual_import summarises the import assessment', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake(['radarr.local:7878/api/v3/queue*' => Http::response(['records' => [
        ['downloadId' => 'XYZ', 'title' => 'Dune.2021.2160p'],
    ]])]);

    $description = resolve(ActionDescriber::class)->describe('resolve_manual_import', [
        'service' => 'radarr',
        'download_id' => 'XYZ',
        'assessment' => ['total' => 2, 'importable' => 1, 'fully_mapped' => false, 'reasons' => ['One file could not be matched.']],
    ]);

    expect($description->title)->toBe('Import download "Dune.2021.2160p"')
        ->and($description->description)->toBe('Radarr will import the 1 of 2 files it could match.')
        ->and($description->details)->toContain(['label' => 'Importable files', 'value' => '1 of 2'])
        ->and($description->details)->toContain(['label' => 'Fully matched', 'value' => 'No'])
        ->and($description->details)->toContain(['label' => 'Notes', 'value' => 'One file could not be matched.']);
});

test('an llm fallback name produces an unverified description', function (): void {
    $description = resolve(ActionDescriber::class)->describe('delete_series', ['sonarr_series_id' => 142], fallbackName: 'Old Show');

    expect($description->title)->toBe('Delete series "Old Show"')->and($description->verified)->toBeFalse();
});

test('an unknown type is undescribable', function (): void {
    resolve(ActionDescriber::class)->describe('launch_rockets', []);
})->throws(UndescribableAction::class, 'launch_rockets');

test('a missing target id is undescribable', function (): void {
    resolve(ActionDescriber::class)->describe('delete_series', ['delete_files' => true]);
})->throws(UndescribableAction::class, 'sonarr_series_id');
