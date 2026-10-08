<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

/**
 * The page's deferred-prop groups. Inertia's client sends one partial reload
 * per group, all at once: props in different groups load in parallel, props
 * sharing a group load in one request, one after another.
 *
 * @return array<string, list<string>>
 */
function deferredPropGroups(TestResponse $testResponse): array
{
    return $testResponse->assertOk()->viewData('page')['deferredProps'] ?? [];
}

test('search gives each upstream service its own deferred group', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    $this->actingAs(User::factory()->member()->create());

    expect(deferredPropGroups($this->get(route('media.search.index', ['q' => 'dune', 'scope' => 'indexers']))))->toBe([
        'sonarr' => ['seriesResults'],
        'radarr' => ['movieResults'],
        'seerr' => ['requestResults'],
        'requesting' => ['requesting'],
        'indexers' => ['indexerResults'],
    ]);
});

test('the series and movie libraries load quality profiles beside the list', function (string $factoryState, string $routeName, string $listProp): void {
    ServiceConnection::factory()->{$factoryState}()->create();
    $this->actingAs(User::factory()->member()->create());

    expect(deferredPropGroups($this->get(route($routeName))))->toBe([
        'default' => [$listProp],
        'qualityProfiles' => ['qualityProfiles'],
    ]);
})->with([
    'series' => ['sonarr', 'media.series.index', 'series'],
    'movies' => ['radarr', 'media.movies.index', 'movies'],
]);

test('the add-series and add-movie lookups load beside the form options', function (string $factoryState, string $routeName): void {
    ServiceConnection::factory()->{$factoryState}()->create();
    $this->actingAs(User::factory()->admin()->create());

    expect(deferredPropGroups($this->get(route($routeName, ['q' => 'dune']))))->toBe([
        'default' => ['qualityProfiles', 'rootFolders'],
        'searchResults' => ['searchResults'],
    ]);
})->with([
    'series' => ['sonarr', 'media.series.create'],
    'movies' => ['radarr', 'media.movies.create'],
]);

test('service health loads Prowlarr indexers beside disk space', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    expect(deferredPropGroups($this->get(route('monitoring.service-health'))))->toBe([
        'default' => ['diskSpace'],
        'prowlarrIndexers' => ['prowlarrIndexers'],
    ]);
});

test('the requests console loads its summary beside the request list', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    $this->actingAs(User::factory()->admin()->create());

    expect(deferredPropGroups($this->get(route('media.requests.index'))))->toBe([
        'default' => ['requests'],
        'summary' => ['summary'],
    ]);
});

test('the connection edit page gives each upstream read its own deferred group', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $connection = ServiceConnection::factory()->sonarr()->create();

    expect(deferredPropGroups($this->get(route('admin.connections.edit', $connection))))->toBe([
        'availableDiskPaths' => ['availableDiskPaths'],
        'sonarrRootFolders' => ['sonarrRootFolders'],
        'arrTags' => ['arrTags'],
    ]);
});
