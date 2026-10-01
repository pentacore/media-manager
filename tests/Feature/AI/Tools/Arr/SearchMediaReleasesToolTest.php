<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\SearchMediaReleasesTool;
use App\Enums\ActionRequestStatus;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ActionTypeConfig::factory()->create(['type' => 'search_media', 'is_enabled' => true, 'requires_approval' => false]);
});

/**
 * Index series 42 and movie 7 on active connections so the server can name
 * every search target without calling the services.
 *
 * @return array{sonarr: ServiceConnection, radarr: ServiceConnection}
 */
function searchReleasesConnections(): array
{
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'is_active' => true]);
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'is_active' => true]);
    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create(['sonarr_id' => 42, 'title' => 'Severance', 'year' => 2022]);
    IndexedMovie::factory()->for($radarr, 'serviceConnection')->create(['radarr_id' => 7, 'title' => 'Dune', 'year' => 2021]);

    return ['sonarr' => $sonarr, 'radarr' => $radarr];
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function searchReleases(array $arguments): array
{
    return json_decode((new SearchMediaReleasesTool)->handle(new Request($arguments)), true);
}

test('queues a search_media request pinned to the active connection', function (array $arguments, array $expectedPayload): void {
    $connections = searchReleasesConnections();

    $result = searchReleases($arguments);

    expect($result['queued'])->toBeTrue()
        ->and($result['status'])->toBe(ActionRequestStatus::Approved->value);

    $actionRequest = ActionRequest::sole();
    expect($actionRequest->type)->toBe('search_media')
        ->and($actionRequest->target_service)->toBe($arguments['service'])
        ->and($actionRequest->payload)->toEqual([
            ...$expectedPayload,
            'service_connection_id' => $connections[$arguments['service']]->id,
        ]);
})->with([
    'series' => [
        ['service' => 'sonarr', 'command' => 'series_search', 'series_id' => 42],
        ['service' => 'sonarr', 'command' => 'series_search', 'series_id' => 42],
    ],
    'season' => [
        ['service' => 'sonarr', 'command' => 'season_search', 'series_id' => 42, 'season_number' => 0],
        ['service' => 'sonarr', 'command' => 'season_search', 'series_id' => 42, 'season_number' => 0],
    ],
    'episodes' => [
        ['service' => 'sonarr', 'command' => 'episode_search', 'series_id' => 42, 'episode_ids' => [5, 6]],
        ['service' => 'sonarr', 'command' => 'episode_search', 'series_id' => 42, 'episode_ids' => [5, 6]],
    ],
    'missing episodes' => [
        ['service' => 'sonarr', 'command' => 'missing_episode_search', 'series_id' => null, 'movie_ids' => null],
        ['service' => 'sonarr', 'command' => 'missing_episode_search'],
    ],
    'movies' => [
        ['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [7]],
        ['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [7]],
    ],
    'missing movies' => [
        ['service' => 'radarr', 'command' => 'missing_movies_search'],
        ['service' => 'radarr', 'command' => 'missing_movies_search'],
    ],
    'cutoff unmet movies' => [
        ['service' => 'radarr', 'command' => 'cutoff_unmet_movies_search'],
        ['service' => 'radarr', 'command' => 'cutoff_unmet_movies_search'],
    ],
]);

test('rejects invalid search arguments without queueing anything', function (array $arguments, string $invalidKey): void {
    searchReleasesConnections();

    $result = searchReleases($arguments);

    expect($result['error'])->toBe('invalid_arguments')
        ->and($result['errors'])->toHaveKey($invalidKey)
        ->and(ActionRequest::count())->toBe(0);
})->with([
    'unknown service' => [['service' => 'whisparr', 'command' => 'movies_search', 'movie_ids' => [7]], 'service'],
    'unknown command' => [['service' => 'radarr', 'command' => 'rss_sync'], 'command'],
    'command from the other service' => [['service' => 'radarr', 'command' => 'missing_episode_search'], 'command'],
    'series search without a series' => [['service' => 'sonarr', 'command' => 'series_search'], 'series_id'],
    'season search without a season' => [['service' => 'sonarr', 'command' => 'season_search', 'series_id' => 42], 'season_number'],
    'episode search without episodes' => [['service' => 'sonarr', 'command' => 'episode_search', 'series_id' => 42], 'episode_ids'],
    'movie search without movies' => [['service' => 'radarr', 'command' => 'movies_search'], 'movie_ids'],
]);

test('returns tool_failed when the service has no active connection', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'is_active' => false]);

    $result = searchReleases(['service' => 'radarr', 'command' => 'missing_movies_search']);

    expect($result['error'])->toBe('tool_failed')
        ->and(ActionRequest::count())->toBe(0);
});

test('Advisory mode blocks the search', function (): void {
    searchReleasesConnections();
    resolve(AiSettings::class)->setMode(AiMode::Advisory);

    $result = searchReleases(['service' => 'radarr', 'command' => 'missing_movies_search']);

    expect($result['error'])->toBe('advisory_mode_blocks_destructive')
        ->and(ActionRequest::count())->toBe(0);
});

test('risk is Destructive', function (): void {
    expect((new SearchMediaReleasesTool)->risk())->toBe(Risk::Destructive);
});
