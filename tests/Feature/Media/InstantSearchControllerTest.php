<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.search.driver', 'typesense');
    config()->set('scout.driver', 'database');
});

test('guests are redirected to login from instant search', function (): void {
    $this->get(route('media.search.instant', ['q' => 'foo']))->assertRedirect(route('login'));
});

test('viewers cannot access instant search', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)
        ->get(route('media.search.instant', ['q' => 'foo']))
        ->assertForbidden();
});

test('q is required', function (): void {
    $member = User::factory()->member()->create();
    $this->actingAs($member)
        ->getJson(route('media.search.instant'))
        ->assertStatus(422);
});

test('returns empty payload when driver is set to fallback', function (): void {
    config()->set('mediamanager.search.driver', 'fallback');
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->getJson(route('media.search.instant', ['q' => 'foo']))
        ->assertOk()
        ->assertExactJson(['series' => [], 'movies' => []]);
});

test('returns matching series and movies as flat hits', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $radarr = ServiceConnection::factory()->radarr()->create();

    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create([
        'sonarr_id' => 11,
        'title' => 'Found Series',
        'year' => 2024,
        'title_slug' => 'found-series',
        'poster_url' => 'https://example.com/s.jpg',
    ]);
    IndexedMovie::factory()->for($radarr, 'serviceConnection')->create([
        'radarr_id' => 22,
        'title' => 'Found Movie',
        'year' => 2023,
        'title_slug' => 'found-movie',
        'poster_url' => 'https://example.com/m.jpg',
    ]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->getJson(route('media.search.instant', ['q' => 'Found']))
        ->assertOk()
        ->assertJsonPath('series.0.id', 11)
        ->assertJsonPath('series.0.title', 'Found Series')
        ->assertJsonPath('series.0.kind', 'series')
        ->assertJsonPath('series.0.poster_url', 'https://example.com/s.jpg')
        ->assertJsonPath('movies.0.id', 22)
        ->assertJsonPath('movies.0.title', 'Found Movie')
        ->assertJsonPath('movies.0.kind', 'movie');
});
