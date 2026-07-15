<?php

use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'test-api-key',
    ]);

    $this->client = new RadarrClient($this->connection);
});

test('getMovies returns array of movies', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 1, 'title' => 'Inception', 'year' => 2010],
            ['id' => 2, 'title' => 'Dune', 'year' => 2021],
        ]),
    ]);

    $result = $this->client->getMovies();

    expect($result)->toHaveCount(2);
    expect($result[0]['title'])->toBe('Inception');
});

test('getMovieById returns single movie', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response([
            'id' => 42,
            'title' => 'Inception',
            'year' => 2010,
        ]),
    ]);

    $result = $this->client->getMovieById(42);

    expect($result['id'])->toBe(42);
    expect($result['title'])->toBe('Inception');
});

test('addMovie sends POST with data', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([
            'id' => 99,
            'title' => 'New Movie',
        ]),
    ]);

    $data = ['title' => 'New Movie', 'tmdbId' => 12345, 'qualityProfileId' => 1, 'rootFolderPath' => '/movies'];
    $result = $this->client->addMovie($data);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['title'] === 'New Movie'
        && $request->data()['tmdbId'] === 12345);

    expect($result['id'])->toBe(99);
});

test('updateMovie sends PUT with data', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response([
            'id' => 42,
            'title' => 'Inception',
            'monitored' => false,
        ]),
    ]);

    $result = $this->client->updateMovie(42, ['monitored' => false]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data()['monitored'] === false);

    expect($result['monitored'])->toBeFalse();
});

test('deleteMovie sends DELETE with deleteFiles param', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/*' => Http::response([], 200),
    ]);

    $this->client->deleteMovie(42, deleteFiles: true);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/movie/42')
        && str_contains($request->url(), 'deleteFiles=true'));
});

test('searchMovies encodes query and returns results', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['title' => 'Inception', 'tmdbId' => 27205],
        ]),
    ]);

    $result = $this->client->searchMovies('inception');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'term=inception'));

    expect($result)->toHaveCount(1);
});

test('getMovieFiles requests the movie files', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/moviefile*' => Http::response([
            ['id' => 601, 'movieId' => 88],
        ]),
    ]);

    $result = $this->client->getMovieFiles(88);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v3/moviefile')
        && str_contains($request->url(), 'movieId=88'));

    expect($result)->toHaveCount(1)
        ->and($result[0]['id'])->toBe(601);
});

test('getMovieFileById requests a single movie file', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/moviefile/601' => Http::response(['id' => 601, 'movieId' => 88]),
    ]);

    $result = $this->client->getMovieFileById(601);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v3/moviefile/601'));

    expect($result['id'])->toBe(601);
});

test('deleteMovieFile sends DELETE to the movie file', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/moviefile/601' => Http::response([], 200),
    ]);

    $this->client->deleteMovieFile(601);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/moviefile/601'));
});

test('setMovieMonitored PUTs the movie editor toggle', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/editor' => Http::response([], 202),
    ]);

    $this->client->setMovieMonitored(88, true);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/movie/editor')
        && $request->data()['movieIds'] === [88]
        && $request->data()['monitored'] === true);
});
