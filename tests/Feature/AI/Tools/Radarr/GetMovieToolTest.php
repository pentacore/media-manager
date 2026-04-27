<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Radarr\GetMovieTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'test',
        'is_active' => true,
    ]);
});

test('returns library movies when no id is given', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([['id' => 1, 'title' => 'Demo']]),
    ]);

    $result = json_decode((string) (new GetMovieTool)->handle(new Request(['movie_id' => null])), true);

    expect($result)->toHaveCount(1);
});

test('returns single movie when id is given', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response(['id' => 42, 'title' => 'Demo']),
    ]);

    $result = json_decode((string) (new GetMovieTool)->handle(new Request(['movie_id' => 42])), true);

    expect($result['id'])->toBe(42);
});

test('risk is Read', function (): void {
    expect((new GetMovieTool)->risk())->toBe(Risk::Read);
});
