<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
});

test('wanted lists missing episodes and movies per service, monitored by default', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/wanted/missing*' => Http::response(['page' => 1, 'totalRecords' => 41, 'records' => [
            ['id' => 70, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 2, 'title' => 'Half Loop', 'airDateUtc' => '2026-09-10T02:00:00Z', 'series' => ['title' => 'Severance']],
        ]]),
        'radarr.local:7878/api/v3/wanted/missing*' => Http::response(['page' => 1, 'totalRecords' => 1, 'records' => [
            ['id' => 10, 'title' => 'Dune', 'year' => 2021, 'digitalRelease' => '2021-10-22T00:00:00Z'],
        ]]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.wanted.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Wanted')
            ->where('filters.tab', 'missing')
            ->where('filters.monitored', true)
            ->loadDeferredProps('sonarr', fn ($reload) => $reload
                ->where('sonarr.error', null)
                ->where('sonarr.records.0', ['id' => 70, 'series_id' => 7, 'title' => 'Severance', 'episode_title' => 'Half Loop', 'code' => 'S01E02', 'air_date_utc' => '2026-09-10T02:00:00Z', 'library_url' => '/media/series/7'])
                ->where('sonarr.meta.total', 41)
                ->where('sonarr.meta.last_page', 3))
            ->loadDeferredProps('radarr', fn ($reload) => $reload
                ->where('radarr.records.0.title', 'Dune')
                ->where('radarr.records.0.air_date_utc', '2021-10-22T00:00:00Z')));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sonarr.local:8989/api/v3/wanted/missing') && $request['monitored'] === 'true');
});

test('the cutoff tab, the unmonitored switch and a page are passed upstream', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/wanted/cutoff*' => Http::response(['page' => 2, 'totalRecords' => 0, 'records' => []]),
        'radarr.local:7878/api/v3/wanted/cutoff*' => Http::response(['page' => 1, 'totalRecords' => 0, 'records' => []]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.wanted.index', ['tab' => 'cutoff', 'monitored' => 0, 'sonarr_page' => 2]))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(['sonarr', 'radarr'], fn ($reload) => $reload->where('sonarr.records', [])));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sonarr.local:8989/api/v3/wanted/cutoff') && $request['monitored'] === 'false' && $request['page'] === 2);
});

test('an unreachable service is an error, not an empty list', function (): void {
    Http::fake([
        'sonarr.local:8989/*' => Http::response([], 503),
        'radarr.local:7878/api/v3/wanted/missing*' => Http::response(['page' => 1, 'totalRecords' => 0, 'records' => []]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.wanted.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('sonarr', fn ($reload) => $reload->where('sonarr.error', 'Sonarr is unreachable right now.')));
});
