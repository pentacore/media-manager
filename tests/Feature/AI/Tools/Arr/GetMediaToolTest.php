<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\GetMediaTool;
use App\Enums\WhisparrVersion;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->sonarrConnection = fn (): ServiceConnection => ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    $this->radarrConnection = fn (): ServiceConnection => ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
    ]);
});

test('lists indexed series with slim projection when service is sonarr and no id is given', function (): void {
    $connection = ($this->sonarrConnection)();
    IndexedSeries::factory()->for($connection, 'serviceConnection')->create([
        'sonarr_id' => 7, 'title' => 'Alpha', 'monitored' => true, 'status' => 'continuing',
    ]);

    $result = json_decode((new GetMediaTool)->handle(new Request(['service' => 'sonarr', 'item_id' => null])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['returned'])->toBe(1);
    expect($result['truncated'])->toBeFalse();
    expect($result['items'][0]['id'])->toBe(7);
    expect($result['items'][0]['title'])->toBe('Alpha');
    expect($result['items'][0])->toHaveKeys([
        'id', 'tvdb_id', 'title', 'year', 'status', 'monitored', 'network', 'genres',
    ]);
    expect($result['items'][0])->not->toHaveKey('overview');
});

test('lists indexed movies with slim projection when service is radarr and no id is given', function (): void {
    $connection = ($this->radarrConnection)();
    IndexedMovie::factory()->for($connection, 'serviceConnection')->create([
        'radarr_id' => 7, 'title' => 'Alpha', 'monitored' => true, 'has_file' => true, 'status' => 'released',
    ]);

    $result = json_decode((new GetMediaTool)->handle(new Request(['service' => 'radarr', 'item_id' => null])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['items'][0]['id'])->toBe(7);
    expect($result['items'][0])->toHaveKeys([
        'id', 'tmdb_id', 'title', 'year', 'status', 'monitored', 'has_file', 'genres',
    ]);
});

test('count_only returns sonarr aggregate counts only', function (): void {
    $connection = ($this->sonarrConnection)();
    IndexedSeries::factory()->count(3)->for($connection, 'serviceConnection')->create(['monitored' => true]);
    IndexedSeries::factory()->count(2)->for($connection, 'serviceConnection')->create(['monitored' => false]);

    $result = json_decode((new GetMediaTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => null, 'count_only' => true, 'monitored' => false,
    ])), true);

    expect($result['matched'])->toBe(2);
    expect($result['library_total'])->toBe(5);
    expect($result['library_monitored'])->toBe(3);
    expect($result['library_unmonitored'])->toBe(2);
    expect($result)->not->toHaveKey('items');
    expect($result)->not->toHaveKey('library_with_file');
});

test('count_only includes file aggregates for radarr', function (): void {
    $connection = ($this->radarrConnection)();
    IndexedMovie::factory()->count(3)->for($connection, 'serviceConnection')->create(['monitored' => true, 'has_file' => true]);
    IndexedMovie::factory()->count(2)->for($connection, 'serviceConnection')->create(['monitored' => false, 'has_file' => false]);

    $result = json_decode((new GetMediaTool)->handle(new Request([
        'service' => 'radarr', 'item_id' => null, 'count_only' => true, 'has_file' => false,
    ])), true);

    expect($result['matched'])->toBe(2);
    expect($result['library_with_file'])->toBe(3);
    expect($result['library_without_file'])->toBe(2);
});

test('monitored filter narrows the sonarr list', function (): void {
    $connection = ($this->sonarrConnection)();
    IndexedSeries::factory()->for($connection, 'serviceConnection')->create(['title' => 'Tracked', 'monitored' => true]);
    IndexedSeries::factory()->for($connection, 'serviceConnection')->create(['title' => 'Ignored', 'monitored' => false]);

    $result = json_decode((new GetMediaTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => null, 'monitored' => false,
    ])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['items'][0]['title'])->toBe('Ignored');
});

test('has_file filter narrows the radarr list', function (): void {
    $connection = ($this->radarrConnection)();
    IndexedMovie::factory()->for($connection, 'serviceConnection')->create(['title' => 'Have', 'has_file' => true]);
    IndexedMovie::factory()->for($connection, 'serviceConnection')->create(['title' => 'Missing', 'has_file' => false]);

    $result = json_decode((new GetMediaTool)->handle(new Request([
        'service' => 'radarr', 'item_id' => null, 'has_file' => false,
    ])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['items'][0]['title'])->toBe('Missing');
});

test('query filter is case-insensitive substring on title', function (): void {
    $connection = ($this->sonarrConnection)();
    IndexedSeries::factory()->for($connection, 'serviceConnection')->create(['title' => 'The Rookie']);
    IndexedSeries::factory()->for($connection, 'serviceConnection')->create(['title' => 'Other']);

    $result = json_decode((new GetMediaTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => null, 'query' => 'rookie',
    ])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['items'][0]['title'])->toBe('The Rookie');
});

test('returns single series from Sonarr when an id is given', function (): void {
    ($this->sonarrConnection)();
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Demo']),
    ]);

    $result = json_decode((new GetMediaTool)->handle(new Request(['service' => 'sonarr', 'item_id' => 42])), true);

    expect($result['id'])->toBe(42);
});

test('returns single movie from Radarr when an id is given', function (): void {
    ($this->radarrConnection)();
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response(['id' => 42, 'title' => 'Demo']),
    ]);

    $result = json_decode((new GetMediaTool)->handle(new Request(['service' => 'radarr', 'item_id' => 42])), true);

    expect($result['id'])->toBe(42);
});

test('lists the whole Whisparr library when service is whisparr and no id is given', function (): void {
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);
    Http::fake(['whisparr.local:6969/api/v3/movie' => Http::response([['id' => 1, 'title' => 'X']])]);

    $result = json_decode((new GetMediaTool)->handle(new Request(['service' => 'whisparr', 'item_id' => null])), true);

    expect($result)->toHaveCount(1);
});

test('returns tool_failed for an unknown service', function (): void {
    $result = json_decode((new GetMediaTool)->handle(new Request(['service' => 'emby', 'item_id' => null])), true);

    expect($result['error'])->toBe('tool_failed');
});

test('risk is Read', function (): void {
    expect((new GetMediaTool)->risk())->toBe(Risk::Read);
});
