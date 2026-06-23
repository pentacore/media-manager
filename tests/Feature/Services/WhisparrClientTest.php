<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use App\Services\Whisparr\WhisparrClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('v3 connection targets the movie resource', function (): void {
    Http::fake(['whisparr.local:6969/api/v3/movie' => Http::response([['id' => 1, 'title' => 'X']])]);
    $connection = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k',
    ]);

    $items = new WhisparrClient($connection)->getItems();

    expect($items)->toHaveCount(1);
    Http::assertSent(fn ($r): bool => str_contains((string) $r->url(), '/api/v3/movie'));
});

test('v2 connection targets the series resource', function (): void {
    Http::fake(['whisparr.local:6969/api/v3/series' => Http::response([['id' => 1, 'title' => 'X']])]);
    $connection = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k',
    ]);

    new WhisparrClient($connection)->getItems();

    Http::assertSent(fn ($r): bool => str_contains((string) $r->url(), '/api/v3/series'));
});

test('deleteItem issues a DELETE with the deleteFiles flag', function (): void {
    Http::fake(['whisparr.local:6969/api/v3/movie/7*' => Http::response(null, 200)]);
    $connection = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k',
    ]);

    new WhisparrClient($connection)->deleteItem(7, true);

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/movie/7')
        && str_contains((string) $r->url(), 'deleteFiles=true'));
});
