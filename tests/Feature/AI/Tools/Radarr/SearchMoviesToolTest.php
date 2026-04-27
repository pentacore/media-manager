<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Radarr\SearchMoviesTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'test',
        'is_active' => true,
    ]);
});

test('searches Radarr movies catalog by query', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['title' => 'Inception', 'year' => 2010, 'tmdbId' => 27205],
        ]),
    ]);

    $result = json_decode((string) (new SearchMoviesTool)->handle(new Request(['query' => 'Inception'])), true);

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Inception');
});

test('returns tool_failed when no Radarr connection is configured', function (): void {
    $this->connection->delete();

    $result = json_decode((string) (new SearchMoviesTool)->handle(new Request(['query' => 'Inception'])), true);

    expect($result['error'])->toBe('tool_failed');
});

test('risk is Read', function (): void {
    expect((new SearchMoviesTool)->risk())->toBe(Risk::Read);
});
