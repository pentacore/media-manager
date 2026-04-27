<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Sonarr\GetSeriesTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
    ]);
});

test('returns library series when no id is given', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([['id' => 1, 'title' => 'Demo']]),
    ]);

    $result = json_decode((string) (new GetSeriesTool)->handle(new Request(['series_id' => null])), true);

    expect($result)->toHaveCount(1);
});

test('returns single series when id is given', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Demo']),
    ]);

    $result = json_decode((string) (new GetSeriesTool)->handle(new Request(['series_id' => 42])), true);

    expect($result['id'])->toBe(42);
});

test('risk is Read', function (): void {
    expect((new GetSeriesTool)->risk())->toBe(Risk::Read);
});
