<?php

declare(strict_types=1);

use App\Ai\Tools\Trakt\TraktGetListTool;
use App\Ai\Tools\Trakt\TraktGetPopularTool;
use App\Ai\Tools\Trakt\TraktGetTrendingTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('services.trakt.base_url', 'https://api.trakt.tv');
    config()->set('services.trakt.client_id', 'trakt-test-id');
});

test('TraktGetTrendingTool returns the parsed array', function (): void {
    Http::fake([
        'api.trakt.tv/movies/trending*' => Http::response([
            ['watchers' => 53, 'movie' => ['title' => 'Inception', 'year' => 2010]],
        ]),
    ]);

    $result = (new TraktGetTrendingTool)->handle(new Request(['media_type' => 'movie']));
    $decoded = json_decode($result, true);

    expect($decoded['results'][0]['movie']['title'])->toBe('Inception');
});

test('TraktGetPopularTool routes media_type tv to /shows/popular', function (): void {
    Http::fake([
        'api.trakt.tv/shows/popular*' => Http::response([
            ['title' => 'Severance', 'year' => 2022],
        ]),
    ]);

    $result = (new TraktGetPopularTool)->handle(new Request(['media_type' => 'tv']));
    $decoded = json_decode($result, true);

    expect($decoded['results'][0]['title'])->toBe('Severance');
});

test('TraktGetListTool returns items for a list id', function (): void {
    Http::fake([
        'api.trakt.tv/lists/777/items*' => Http::response([
            ['type' => 'show', 'show' => ['title' => 'The Bear']],
        ]),
    ]);

    $result = (new TraktGetListTool)->handle(new Request(['list_id' => 777]));
    $decoded = json_decode($result, true);

    expect($decoded['results'][0]['show']['title'])->toBe('The Bear');
});

test('TraktGetTrendingTool error envelope on service failure', function (): void {
    Http::fake(['api.trakt.tv/movies/trending*' => Http::response(['error' => 'boom'], 500)]);

    $result = (new TraktGetTrendingTool)->handle(new Request(['media_type' => 'movie']));
    $decoded = json_decode($result, true);

    expect($decoded['error'])->toBe('tool_failed');
});
