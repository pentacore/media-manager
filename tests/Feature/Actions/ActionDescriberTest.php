<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\UndescribableAction;
use Illuminate\Http\Client\Request;
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

    $actionDescription = resolve(ActionDescriber::class)->describe('delete_series', ['sonarr_series_id' => 142, 'delete_files' => true]);

    expect($actionDescription->title)->toBe('Delete series "Severance (2022)"')
        ->and($actionDescription->description)->toBe('Sonarr will delete the series and its files from disk.')
        ->and($actionDescription->details)->toContain(['label' => 'Delete files', 'value' => 'Yes'])
        ->and($actionDescription->verified)->toBeTrue();
});

test('delete_series reads delete_files the way the executor casts it', function (mixed $deleteFiles): void {
    describerSeries();

    $actionDescription = resolve(ActionDescriber::class)->describe('delete_series', ['sonarr_series_id' => 142, 'delete_files' => $deleteFiles]);

    expect($actionDescription->description)->toBe('Sonarr will delete the series and its files from disk.')
        ->and($actionDescription->details)->toContain(['label' => 'Delete files', 'value' => 'Yes']);
})->with([
    'string true' => ['true'],
    'integer one' => [1],
]);

test('delete_movie without deleting files says files are kept', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    IndexedMovie::factory()->for($radarr, 'serviceConnection')->create(['radarr_id' => 55, 'title' => 'Dune', 'year' => 2021]);

    $actionDescription = resolve(ActionDescriber::class)->describe('delete_movie', ['radarr_movie_id' => 55, 'delete_files' => false]);

    expect($actionDescription->title)->toBe('Delete movie "Dune (2021)"')
        ->and($actionDescription->description)->toBe('Radarr will remove the movie but keep its files on disk.')
        ->and($actionDescription->details)->toContain(['label' => 'Delete files', 'value' => 'No']);
});

test('add_series lists the quality profile name, root folder and monitoring', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([['title' => 'Shogun', 'year' => 2024]]),
        'sonarr.local:8989/api/v3/qualityprofile*' => Http::response([['id' => 4, 'name' => 'HD-1080p']]),
    ]);

    $actionDescription = resolve(ActionDescriber::class)->describe('add_series', [
        'tvdb_id' => 999, 'quality_profile_id' => 4, 'root_folder_path' => '/tv', 'monitored' => true, 'season_folder' => true,
    ]);

    expect($actionDescription->title)->toBe('Add series "Shogun (2024)"')
        ->and($actionDescription->description)->toBe('Sonarr will add the series and search for it.')
        ->and($actionDescription->details)->toContain(['label' => 'Quality profile', 'value' => 'HD-1080p'])
        ->and($actionDescription->details)->toContain(['label' => 'Root folder', 'value' => '/tv'])
        ->and($actionDescription->details)->toContain(['label' => 'Monitored', 'value' => 'Yes'])
        ->and($actionDescription->details)->toContain(['label' => 'Season folders', 'value' => 'Yes']);
});

test('add_series without season_folder shows the executor default', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake([
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([['title' => 'Shogun', 'year' => 2024]]),
        'sonarr.local:8989/api/v3/qualityprofile*' => Http::response([['id' => 4, 'name' => 'HD-1080p']]),
    ]);

    $actionDescription = resolve(ActionDescriber::class)->describe('add_series', ['tvdb_id' => 999, 'quality_profile_id' => 4, 'root_folder_path' => '/tv']);

    expect($actionDescription->details)->toContain(['label' => 'Season folders', 'value' => 'Yes'])
        ->and($actionDescription->details)->toContain(['label' => 'Monitored', 'value' => 'Yes']);
});

test('add_movie omits season folders', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake([
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([['title' => 'Dune', 'year' => 2021]]),
        'radarr.local:7878/api/v3/qualityprofile*' => Http::response([['id' => 4, 'name' => 'HD-1080p']]),
    ]);

    $actionDescription = resolve(ActionDescriber::class)->describe('add_movie', ['tmdb_id' => 438631, 'quality_profile_id' => 4, 'root_folder_path' => '/movies']);

    expect(array_column($actionDescription->details, 'label'))->not->toContain('Season folders');
});

test('monitor_series words starting and stopping monitoring', function (bool $monitored, string $title, string $effect): void {
    describerSeries();

    $actionDescription = resolve(ActionDescriber::class)->describe('monitor_series', ['series_id' => 142, 'monitored' => $monitored]);

    expect($actionDescription->title)->toBe($title)->and($actionDescription->description)->toBe($effect);
})->with([
    'monitor' => [true, 'Monitor series "Severance (2022)"', 'Sonarr will start monitoring the series.'],
    'unmonitor' => [false, 'Unmonitor series "Severance (2022)"', 'Sonarr will stop monitoring the series.'],
]);

test('monitor_series without a monitored flag monitors like the executor', function (): void {
    describerSeries();

    $actionDescription = resolve(ActionDescriber::class)->describe('monitor_series', ['series_id' => 142]);

    expect($actionDescription->title)->toBe('Monitor series "Severance (2022)"')
        ->and($actionDescription->description)->toBe('Sonarr will start monitoring the series.')
        ->and($actionDescription->details)->toContain(['label' => 'Monitored', 'value' => 'Yes']);
});

test('set_series_quality_profile names the new profile', function (): void {
    describerSeries();
    Http::fake(['sonarr.local:8989/api/v3/qualityprofile*' => Http::response([['id' => 6, 'name' => 'Ultra-HD']])]);

    $actionDescription = resolve(ActionDescriber::class)->describe('set_series_quality_profile', ['series_id' => 142, 'quality_profile_id' => 6]);

    expect($actionDescription->title)->toBe('Change quality profile of series "Severance (2022)"')
        ->and($actionDescription->description)->toBe('Sonarr will switch the series to the "Ultra-HD" quality profile.');
});

test('seerr request types name the requested media', function (string $type, string $title): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055']);
    Http::fake([
        'seerr.local:5055/api/v1/request/12' => Http::response(['type' => 'tv', 'media' => ['tmdbId' => 95396], 'requestedBy' => ['displayName' => 'Alex']]),
        'seerr.local:5055/api/v1/tv/95396' => Http::response(['name' => 'Severance', 'firstAirDate' => '2022-02-18']),
    ]);

    $actionDescription = resolve(ActionDescriber::class)->describe($type, ['seerr_request_id' => 12]);

    expect($actionDescription->title)->toBe($title)
        ->and($actionDescription->details)->toContain(['label' => 'Requested by', 'value' => 'Alex']);
})->with([
    ['approve_seerr_request', 'Approve Seerr request for "Severance (2022)"'],
    ['decline_seerr_request', 'Decline Seerr request for "Severance (2022)"'],
    ['cleanup_seerr_request', 'Clean up Seerr request for "Severance (2022)"'],
]);

test('emby_library_scan names the emby server', function (): void {
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);

    $actionDescription = resolve(ActionDescriber::class)->describe('emby_library_scan', []);

    expect($actionDescription->title)->toBe('Scan the Emby library')
        ->and($actionDescription->description)->toBe('Emby server "Living Room" will rescan its libraries to pick up changes.')
        ->and($actionDescription->verified)->toBeTrue();
});

test('emby_library_scan names the active emby server the executor scans, not a pinned one', function (): void {
    ServiceConnection::factory()->emby()->create(['name' => 'Living Room']);
    $bedroom = ServiceConnection::factory()->emby()->create(['name' => 'Bedroom']);

    $actionDescription = resolve(ActionDescriber::class)->describe('emby_library_scan', ['service_connection_id' => $bedroom->id]);

    expect($actionDescription->description)->toBe('Emby server "Living Room" will rescan its libraries to pick up changes.');
});

test('remove_stuck_download describes blocklisting and searching', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake(['sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
        ['downloadId' => 'ABC', 'title' => 'Bad.Release.1080p'],
    ]])]);

    $actionDescription = resolve(ActionDescriber::class)->describe('remove_stuck_download', [
        'service' => 'sonarr', 'download_id' => 'ABC', 'blocklist' => true, 'search_replacement' => true,
    ]);

    expect($actionDescription->title)->toBe('Remove stuck download "Bad.Release.1080p"')
        ->and($actionDescription->description)->toBe('Sonarr will remove the download from its queue and delete its data, blocklist the release, then search for a replacement.')
        ->and($actionDescription->details)->toContain(['label' => 'Blocklist release', 'value' => 'Yes']);
});

test('resolve_manual_import summarises the import assessment', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake(['radarr.local:7878/api/v3/queue*' => Http::response(['records' => [
        ['downloadId' => 'XYZ', 'title' => 'Dune.2021.2160p'],
    ]])]);

    $actionDescription = resolve(ActionDescriber::class)->describe('resolve_manual_import', [
        'service' => 'radarr',
        'download_id' => 'XYZ',
        'assessment' => ['total' => 2, 'importable' => 1, 'fully_mapped' => false, 'reasons' => ['One file could not be matched.']],
    ]);

    expect($actionDescription->title)->toBe('Import download "Dune.2021.2160p"')
        ->and($actionDescription->description)->toBe('Radarr will import the 1 of 2 files it could match.')
        ->and($actionDescription->details)->toContain(['label' => 'Importable files', 'value' => '1 of 2'])
        ->and($actionDescription->details)->toContain(['label' => 'Fully matched', 'value' => 'No'])
        ->and($actionDescription->details)->toContain(['label' => 'Notes', 'value' => 'One file could not be matched.']);
});

test('an llm fallback name produces an unverified description', function (): void {
    $actionDescription = resolve(ActionDescriber::class)->describe('delete_series', ['sonarr_series_id' => 142], fallbackName: 'Old Show');

    expect($actionDescription->title)->toBe('Delete series "Old Show"')->and($actionDescription->verified)->toBeFalse();
});

test('an unknown type is undescribable', function (): void {
    resolve(ActionDescriber::class)->describe('launch_rockets', []);
})->throws(UndescribableAction::class, 'launch_rockets');

test('a missing target id is undescribable', function (): void {
    resolve(ActionDescriber::class)->describe('delete_series', ['delete_files' => true]);
})->throws(UndescribableAction::class, 'sonarr_series_id');

function describerMovie(): void
{
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'name' => 'Radarr']);
    IndexedMovie::factory()->for($radarr, 'serviceConnection')->create(['radarr_id' => 10, 'title' => 'Dune', 'year' => 2021]);
}

test('monitor_episodes names the season or the episode count', function (array $payload, string $title, string $effect): void {
    describerSeries();

    $actionDescription = resolve(ActionDescriber::class)->describe('monitor_episodes', ['series_id' => 142, ...$payload]);

    expect($actionDescription->title)->toBe($title)
        ->and($actionDescription->description)->toBe($effect)
        ->and($actionDescription->verified)->toBeTrue();
})->with([
    'season' => [['episode_ids' => [1, 2], 'season_number' => 2, 'monitored' => true], 'Monitor season 2 of series "Severance (2022)"', 'Sonarr will start monitoring every episode in the season.'],
    'episodes' => [['episode_ids' => [1, 2, 3], 'monitored' => false], 'Unmonitor 3 episodes of series "Severance (2022)"', 'Sonarr will stop monitoring the selected episodes.'],
    'one episode' => [['episode_ids' => [1], 'monitored' => true], 'Monitor 1 episode of series "Severance (2022)"', 'Sonarr will start monitoring the selected episodes.'],
]);

test('search_media words each targeted search', function (array $payload, string $title): void {
    describerSeries();

    expect(resolve(ActionDescriber::class)->describe('search_media', ['service' => 'sonarr', 'series_id' => 142, ...$payload])->title)->toBe($title);
})->with([
    'series' => [['command' => 'series_search'], 'Search for series "Severance (2022)"'],
    'season' => [['command' => 'season_search', 'season_number' => 2], 'Search for season 2 of series "Severance (2022)"'],
    'episode' => [['command' => 'episode_search', 'episode_ids' => [5]], 'Search for 1 episode of series "Severance (2022)"'],
]);

test('search_media names the movie', function (): void {
    describerMovie();

    expect(resolve(ActionDescriber::class)->describe('search_media', ['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [10]])->title)
        ->toBe('Search for movie "Dune (2021)"');
});

test('search_media describes a count instead of naming only the first movie when more than one is targeted', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'name' => 'Radarr']);

    $actionDescription = resolve(ActionDescriber::class)->describe('search_media', ['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [10, 11, 12]]);

    expect($actionDescription->title)->toBe('Search for 3 movies')
        ->and($actionDescription->description)->toBe('Radarr will search its indexers for the 3 movies.')
        ->and($actionDescription->verified)->toBeTrue();
});

test('library-wide searches name the server', function (string $command, string $title): void {
    describerSeries();
    describerMovie();

    $actionDescription = resolve(ActionDescriber::class)->describe('search_media', ['command' => $command]);

    expect($actionDescription->title)->toBe($title)->and($actionDescription->verified)->toBeTrue();
})->with([
    'missing episodes' => ['missing_episode_search', 'Search for all missing episodes in Sonarr server "Sonarr"'],
    'cutoff episodes' => ['cutoff_unmet_episode_search', 'Search for all episodes below their quality cutoff in Sonarr server "Sonarr"'],
    'missing movies' => ['missing_movies_search', 'Search for all missing movies in Radarr server "Radarr"'],
    'cutoff movies' => ['cutoff_unmet_movies_search', 'Search for all movies below their quality cutoff in Radarr server "Radarr"'],
]);

test('grab_release names the target and lists the release facts', function (): void {
    describerSeries();

    $actionDescription = resolve(ActionDescriber::class)->describe('grab_release', [
        'service' => 'sonarr',
        'series_id' => 142,
        'guid' => 'g',
        'indexer_id' => 3,
        'release' => ['title' => 'Severance.S02E01.1080p', 'quality' => 'WEBDL-1080p', 'size' => 2_147_483_648, 'indexer' => 'NZBgeek', 'rejections' => ['Not an upgrade']],
    ]);

    expect($actionDescription->title)->toBe('Grab release for series "Severance (2022)"')
        ->and($actionDescription->description)->toBe('Sonarr will send "Severance.S02E01.1080p" to its download client.')
        ->and($actionDescription->details)->toContain(['label' => 'Quality', 'value' => 'WEBDL-1080p'])
        ->and($actionDescription->details)->toContain(['label' => 'Size', 'value' => '2.0 GB'])
        ->and($actionDescription->details)->toContain(['label' => 'Rejected by', 'value' => 'Not an upgrade']);
});

test('the new types are undescribable without their target', function (string $type, array $payload): void {
    expect(fn () => resolve(ActionDescriber::class)->describe($type, $payload))->toThrow(UndescribableAction::class);
})->with([
    'episodes without series' => ['monitor_episodes', ['episode_ids' => [1]]],
    'search without command' => ['search_media', ['service' => 'sonarr', 'series_id' => 1]],
    'grab without release' => ['grab_release', ['service' => 'sonarr', 'series_id' => 1]],
    'grab without service' => ['grab_release', ['release' => ['title' => 'x']]],
]);

function describerSecondSonarr(): ServiceConnection
{
    $second = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr2.local:8989', 'name' => 'Anime']);
    IndexedSeries::factory()->for($second, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance (Anime Cut)', 'year' => 2022]);

    return $second;
}

test("monitor_episodes describes the pinned connection's series, not the default one", function (): void {
    describerSeries();
    $serviceConnection = describerSecondSonarr();

    $actionDescription = resolve(ActionDescriber::class)->describe('monitor_episodes', [
        'series_id' => 142, 'episode_ids' => [1], 'monitored' => true, 'service_connection_id' => $serviceConnection->id,
    ]);

    expect($actionDescription->title)->toBe('Monitor 1 episode of series "Severance (Anime Cut) (2022)"')
        ->and($actionDescription->verified)->toBeTrue();
});

test('search_media targets the series of the pinned connection', function (): void {
    describerSeries();
    $serviceConnection = describerSecondSonarr();

    $actionDescription = resolve(ActionDescriber::class)->describe('search_media', [
        'service' => 'sonarr', 'series_id' => 142, 'command' => 'series_search', 'service_connection_id' => $serviceConnection->id,
    ]);

    expect($actionDescription->title)->toBe('Search for series "Severance (Anime Cut) (2022)"');
});

test('grab_release names the series of the pinned connection', function (): void {
    describerSeries();
    $serviceConnection = describerSecondSonarr();

    $actionDescription = resolve(ActionDescriber::class)->describe('grab_release', [
        'service' => 'sonarr',
        'series_id' => 142,
        'guid' => 'g',
        'indexer_id' => 3,
        'service_connection_id' => $serviceConnection->id,
        'release' => ['title' => 'Severance.S02E01.1080p'],
    ]);

    expect($actionDescription->title)->toBe('Grab release for series "Severance (Anime Cut) (2022)"');
});

test('a library-wide search honours a connection pin, naming that instance', function (): void {
    describerSeries();
    $serviceConnection = describerSecondSonarr();

    $actionDescription = resolve(ActionDescriber::class)->describe('search_media', [
        'command' => 'missing_episode_search', 'service_connection_id' => $serviceConnection->id,
    ]);

    expect($actionDescription->title)->toBe('Search for all missing episodes in Sonarr server "Anime"')
        ->and($actionDescription->verified)->toBeTrue();
});

test('a library-wide search aborts when the pin names a connection of the wrong service', function (): void {
    describerSeries();
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    expect(fn () => resolve(ActionDescriber::class)->describe('search_media', [
        'command' => 'missing_episode_search', 'service_connection_id' => $radarr->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('monitor_episodes aborts when the pin names a connection of the wrong service', function (): void {
    describerSeries();
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    expect(fn () => resolve(ActionDescriber::class)->describe('monitor_episodes', [
        'series_id' => 142, 'episode_ids' => [1], 'monitored' => true, 'service_connection_id' => $radarr->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('a series-targeted search_media aborts when the pin names a connection of the wrong service', function (): void {
    describerSeries();
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    expect(fn () => resolve(ActionDescriber::class)->describe('search_media', [
        'service' => 'sonarr', 'series_id' => 142, 'command' => 'series_search', 'service_connection_id' => $radarr->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('a movie search_media aborts when the pin names a connection of the wrong service', function (): void {
    describerMovie();
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    expect(fn () => resolve(ActionDescriber::class)->describe('search_media', [
        'service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [10], 'service_connection_id' => $sonarr->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('grab_release aborts when the pin names a connection of the wrong service', function (): void {
    describerSeries();
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    expect(fn () => resolve(ActionDescriber::class)->describe('grab_release', [
        'service' => 'sonarr',
        'series_id' => 142,
        'guid' => 'g',
        'indexer_id' => 3,
        'service_connection_id' => $radarr->id,
        'release' => ['title' => 'Severance.S02E01.1080p'],
    ]))->toThrow(InvalidArgumentException::class);
});

test('whisparr_search names the Whisparr item of the pinned connection', function (): void {
    $whisparr = ServiceConnection::factory()->whisparr()->create(['url' => 'http://whisparr.local:6969', 'name' => 'Whisparr']);
    Http::fake(['whisparr.local:6969/api/v3/movie' => Http::response([['id' => 11, 'title' => 'Aurora Scene', 'year' => 2024]])]);

    $actionDescription = resolve(ActionDescriber::class)->describe('whisparr_search', ['whisparr_item_id' => 11, 'service_connection_id' => $whisparr->id]);

    expect($actionDescription->title)->toBe('Search for item "Aurora Scene (2024)"')
        ->and($actionDescription->description)->toBe('Whisparr will search its indexers for the item.')
        ->and($actionDescription->verified)->toBeTrue();
});

test('whisparr_search aborts when the pin names a connection of another service', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    expect(fn () => resolve(ActionDescriber::class)->describe('whisparr_search', ['whisparr_item_id' => 11, 'service_connection_id' => $sonarr->id]))
        ->toThrow(InvalidArgumentException::class);
});

// R12 follow-up: whisparr_delete_item / whisparr_add_item / whisparr_monitor_item /
// whisparr_set_quality_profile now describe via a strict pin too (whisparrItem()/
// whisparrLookup() with strictPin: true), matching their executors. A present pin
// naming a connection of another service must abort rather than silently
// describing another instance's item as verified.
// A missing pin is refused too, even with an active Whisparr connection to
// fall back to: the executors resolve strictly, so a verified card for an
// unpinned request would describe an action that can never run.
test('the Whisparr describer arms abort when the request carries no pin, even with an active Whisparr connection', function (string $type, array $payload): void {
    ServiceConnection::factory()->whisparr()->create(['url' => 'http://whisparr.local:6969']);

    expect(fn () => resolve(ActionDescriber::class)->describe($type, $payload))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'whisparr_delete_item' => ['whisparr_delete_item', ['whisparr_item_id' => 11]],
    'whisparr_add_item' => ['whisparr_add_item', ['tmdb_id' => 1]],
    'whisparr_monitor_item' => ['whisparr_monitor_item', ['whisparr_item_id' => 11]],
    'whisparr_set_quality_profile' => ['whisparr_set_quality_profile', ['whisparr_item_id' => 11, 'quality_profile_id' => 2]],
    'whisparr_search' => ['whisparr_search', ['whisparr_item_id' => 11]],
]);

test('a Whisparr item is named from the cached library list without a by-id lookup', function (): void {
    $whisparr = ServiceConnection::factory()->whisparr()->create(['url' => 'http://whisparr.local:6969', 'name' => 'Whisparr']);
    Http::fake(['whisparr.local:6969/api/v3/movie' => Http::response([
        ['id' => 11, 'title' => 'Aurora Scene', 'year' => 2024],
        ['id' => 12, 'title' => 'Borealis Scene', 'year' => 2023],
    ])]);

    $actionDescriber = resolve(ActionDescriber::class);
    $actionDescription = $actionDescriber->describe('whisparr_monitor_item', ['whisparr_item_id' => 11, 'monitored' => true, 'service_connection_id' => $whisparr->id]);

    expect($actionDescription->title)->toContain('Aurora Scene (2024)')
        ->and($actionDescription->verified)->toBeTrue()
        ->and($actionDescriber->describe('whisparr_search', ['whisparr_item_id' => 12, 'service_connection_id' => $whisparr->id])->title)
        ->toBe('Search for item "Borealis Scene (2023)"');

    // One list read serves both items; no per-item lookup is sent.
    Http::assertSentCount(1);
});

test('a Whisparr item missing from the cached list falls back to the by-id lookup', function (): void {
    $whisparr = ServiceConnection::factory()->whisparr()->create(['url' => 'http://whisparr.local:6969', 'name' => 'Whisparr']);
    Http::fake([
        'whisparr.local:6969/api/v3/movie/13' => Http::response(['id' => 13, 'title' => 'Cirrus Scene', 'year' => 2022]),
        'whisparr.local:6969/api/v3/movie' => Http::response([['id' => 11, 'title' => 'Aurora Scene', 'year' => 2024]]),
    ]);

    $actionDescription = resolve(ActionDescriber::class)->describe('whisparr_search', ['whisparr_item_id' => 13, 'service_connection_id' => $whisparr->id]);

    expect($actionDescription->title)->toBe('Search for item "Cirrus Scene (2022)"');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v3/movie/13'));
});

test('the Whisparr describer arms abort when the pin names a connection of another service', function (string $type, array $payload): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    expect(fn () => resolve(ActionDescriber::class)->describe($type, [...$payload, 'service_connection_id' => $sonarr->id]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'whisparr_delete_item' => ['whisparr_delete_item', ['whisparr_item_id' => 11]],
    'whisparr_add_item' => ['whisparr_add_item', ['tmdb_id' => 1]],
    'whisparr_monitor_item' => ['whisparr_monitor_item', ['whisparr_item_id' => 11]],
    'whisparr_set_quality_profile' => ['whisparr_set_quality_profile', ['whisparr_item_id' => 11, 'quality_profile_id' => 2]],
]);
