<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('seeds Sonarr series from getSeries response', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'sk',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'tvdbId' => 11, 'title' => 'Alpha', 'year' => 2020, 'status' => 'continuing', 'monitored' => true],
            ['id' => 2, 'tvdbId' => 12, 'title' => 'Beta', 'year' => 2021, 'status' => 'ended', 'monitored' => false],
        ]),
    ]);

    $this->artisan('typesense:seed', ['--service' => 'sonarr'])->assertOk();

    $this->assertDatabaseHas('indexed_series', [
        'service_connection_id' => $connection->id,
        'sonarr_id' => 1,
        'title' => 'Alpha',
    ]);
    $this->assertDatabaseHas('indexed_series', [
        'service_connection_id' => $connection->id,
        'sonarr_id' => 2,
        'title' => 'Beta',
    ]);
    expect(IndexedSeries::query()->count())->toBe(2);
});

test('seeds Radarr movies from getMovies response', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'rk',
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 1, 'tmdbId' => 100, 'title' => 'Alpha Movie', 'year' => 2019, 'status' => 'released', 'monitored' => true, 'hasFile' => true],
        ]),
    ]);

    $this->artisan('typesense:seed', ['--service' => 'radarr'])->assertOk();

    $this->assertDatabaseHas('indexed_movies', [
        'service_connection_id' => $connection->id,
        'radarr_id' => 1,
        'title' => 'Alpha Movie',
        'has_file' => true,
    ]);
    expect(IndexedMovie::query()->count())->toBe(1);
});

test('--fresh truncates existing rows for the targeted connection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'sk',
    ]);
    IndexedSeries::factory()->for($connection, 'serviceConnection')->create([
        'sonarr_id' => 999,
        'title' => 'Stale',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            ['id' => 1, 'title' => 'Fresh'],
        ]),
    ]);

    $this->artisan('typesense:seed', ['--service' => 'sonarr', '--fresh' => true])->assertOk();

    expect(IndexedSeries::query()->where('sonarr_id', 999)->exists())->toBeFalse();
    expect(IndexedSeries::query()->where('sonarr_id', 1)->exists())->toBeTrue();
});

test('rejects an invalid --service option', function (): void {
    $this->artisan('typesense:seed', ['--service' => 'unknown'])->assertFailed();
});
