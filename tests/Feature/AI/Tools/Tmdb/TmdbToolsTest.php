<?php

declare(strict_types=1);

use App\Ai\Tools\Tmdb\TmdbGetCreditsTool;
use App\Ai\Tools\Tmdb\TmdbGetSimilarTool;
use App\Ai\Tools\Tmdb\TmdbGetTitleTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
    config()->set('services.tmdb.api_key', 'tmdb-test-key');
});

test('TmdbGetTitleTool returns parsed json verbatim', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205*' => Http::response([
            'id' => 27205, 'title' => 'Inception', 'tagline' => 'Your mind is the scene of the crime.',
        ]),
    ]);

    $result = (new TmdbGetTitleTool)->handle(new Request(['tmdb_id' => 27205, 'media_type' => 'movie']));
    $decoded = json_decode($result, true);

    expect($decoded['title'])->toBe('Inception');
    expect($decoded['tagline'])->toBe('Your mind is the scene of the crime.');
});

test('TmdbGetTitleTool error envelope when service throws', function (): void {
    Http::fake(['api.themoviedb.org/3/movie/0*' => Http::response(['status_message' => 'not found'], 404)]);

    $result = (new TmdbGetTitleTool)->handle(new Request(['tmdb_id' => 0, 'media_type' => 'movie']));
    $decoded = json_decode($result, true);

    expect($decoded['error'])->toBe('tool_failed');
});

test('TmdbGetSimilarTool returns the results array', function (): void {
    Http::fake([
        'api.themoviedb.org/3/tv/1399/similar*' => Http::response([
            'page' => 1,
            'results' => [['id' => 60625, 'name' => 'Rick and Morty']],
        ]),
    ]);

    $result = (new TmdbGetSimilarTool)->handle(new Request(['tmdb_id' => 1399, 'media_type' => 'tv']));
    $decoded = json_decode($result, true);

    expect($decoded['results'][0]['name'])->toBe('Rick and Morty');
});

test('TmdbGetCreditsTool returns the cast/crew payload', function (): void {
    Http::fake([
        'api.themoviedb.org/3/movie/27205/credits*' => Http::response([
            'cast' => [['name' => 'Leonardo DiCaprio']],
            'crew' => [['name' => 'Christopher Nolan', 'job' => 'Director']],
        ]),
    ]);

    $result = (new TmdbGetCreditsTool)->handle(new Request(['tmdb_id' => 27205, 'media_type' => 'movie']));
    $decoded = json_decode($result, true);

    expect($decoded['cast'][0]['name'])->toBe('Leonardo DiCaprio');
});
