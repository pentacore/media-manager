<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('mediamanager.search.driver', 'fallback');
    Http::preventStrayRequests();
    // Deactivated, not missing: an inactive connection must count as none.
    foreach (['sonarr', 'radarr', 'seerr', 'prowlarr', 'whisparr'] as $state) {
        ServiceConnection::factory()->{$state}()->inactive()->create();
    }
});

test('a Sonarr or Radarr page without an active connection goes to the dashboard with the standard toast', function (string $routeName, array $parameters, string $message): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route($routeName, $parameters))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => $message]);

    Http::assertNothingSent();
})->with([
    'series index' => ['media.series.index', [], 'No active Sonarr connection configured.'],
    'series page' => ['media.series.show', [7], 'No active Sonarr connection configured.'],
    'add series form' => ['media.series.create', [], 'No active Sonarr connection configured.'],
    'movies index' => ['media.movies.index', [], 'No active Radarr connection configured.'],
    'movie page' => ['media.movies.show', [7], 'No active Radarr connection configured.'],
    'add movie form' => ['media.movies.create', [], 'No active Radarr connection configured.'],
]);

test('adding a series or movie without an active connection goes to the dashboard with the standard toast', function (string $routeName, array $data, string $message): void {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route($routeName), $data)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => $message]);

    Http::assertNothingSent();
})->with([
    'series' => ['media.series.store', ['title' => 'New Show', 'tvdbId' => 12345, 'qualityProfileId' => 1, 'rootFolderPath' => '/tv'], 'No active Sonarr connection configured.'],
    'movie' => ['media.movies.store', ['title' => 'Dune', 'tmdbId' => 438631, 'qualityProfileId' => 1, 'rootFolderPath' => '/movies'], 'No active Radarr connection configured.'],
]);

test('search without active connections names each missing service and links none', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.search.index', ['q' => 'dune', 'scope' => 'indexers']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections', ['sonarr' => null, 'radarr' => null, 'seerr' => null])
            ->where('requesting', null)
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('seriesResults', ['results' => [], 'error' => 'No active Sonarr connection configured.'])
                ->where('movieResults', ['results' => [], 'error' => 'No active Radarr connection configured.'])
                ->where('requestResults', ['results' => [], 'error' => 'No active Seerr connection configured.'])
                ->where('indexerResults', ['results' => [], 'error' => 'No active Prowlarr connection configured.'])));

    Http::assertNothingSent();
});

test('the indexed search names a missing Radarr too', function (): void {
    config()->set('mediamanager.search.driver', 'typesense');
    config()->set('scout.driver', 'database');

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.search.index', ['q' => 'dune']))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload
            ->where('movieResults', ['results' => [], 'error' => 'No active Radarr connection configured.'])));
});

test('a viewer without an active Seerr gets no Seerr link', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('media.search.index', ['q' => 'dune']))
        ->assertInertia(fn ($page) => $page->where('connections', ['sonarr' => null, 'radarr' => null, 'seerr' => null]));
});

test('Whisparr without an active connection renders an unconnected library and sends a title page back to it', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('media.whisparr.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Whisparr/Index')->where('connection', null)->missing('library'));
    $this->actingAs($admin)
        ->get(route('media.whisparr.show', ['id' => 11]))
        ->assertRedirect(route('media.whisparr.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'No active Whisparr connection configured.']);

    Http::assertNothingSent();
});
