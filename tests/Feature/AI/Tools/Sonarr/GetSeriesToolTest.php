<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Sonarr\GetSeriesTool;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
    ]);
});

test('lists indexed series with slim projection when no id is given', function (): void {
    IndexedSeries::factory()->for($this->connection, 'serviceConnection')->create([
        'sonarr_id' => 7,
        'title' => 'Alpha',
        'monitored' => true,
        'status' => 'continuing',
    ]);

    $result = json_decode((new GetSeriesTool)->handle(new Request(['series_id' => null])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['returned'])->toBe(1);
    expect($result['truncated'])->toBeFalse();
    expect($result['series'][0]['id'])->toBe(7);
    expect($result['series'][0]['title'])->toBe('Alpha');
    expect($result['series'][0])->toHaveKeys([
        'id', 'tvdb_id', 'title', 'year', 'status', 'monitored', 'network', 'genres',
    ]);
    expect($result['series'][0])->not->toHaveKey('overview');
});

test('count_only returns aggregate counts only', function (): void {
    IndexedSeries::factory()->count(3)->for($this->connection, 'serviceConnection')->create(['monitored' => true]);
    IndexedSeries::factory()->count(2)->for($this->connection, 'serviceConnection')->create(['monitored' => false]);

    $result = json_decode((new GetSeriesTool)->handle(new Request([
        'series_id' => null,
        'count_only' => true,
        'monitored' => false,
    ])), true);

    expect($result['matched'])->toBe(2);
    expect($result['library_total'])->toBe(5);
    expect($result['library_monitored'])->toBe(3);
    expect($result['library_unmonitored'])->toBe(2);
    expect($result)->not->toHaveKey('series');
});

test('monitored filter narrows the list', function (): void {
    IndexedSeries::factory()->for($this->connection, 'serviceConnection')->create(['title' => 'Tracked', 'monitored' => true]);
    IndexedSeries::factory()->for($this->connection, 'serviceConnection')->create(['title' => 'Ignored', 'monitored' => false]);

    $result = json_decode((new GetSeriesTool)->handle(new Request([
        'series_id' => null,
        'monitored' => false,
    ])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['series'][0]['title'])->toBe('Ignored');
});

test('query filter is case-insensitive substring on title', function (): void {
    IndexedSeries::factory()->for($this->connection, 'serviceConnection')->create(['title' => 'The Rookie']);
    IndexedSeries::factory()->for($this->connection, 'serviceConnection')->create(['title' => 'Other']);

    $result = json_decode((new GetSeriesTool)->handle(new Request([
        'series_id' => null,
        'query' => 'rookie',
    ])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['series'][0]['title'])->toBe('The Rookie');
});

test('returns single series from Sonarr when id is given', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Demo']),
    ]);

    $result = json_decode((new GetSeriesTool)->handle(new Request(['series_id' => 42])), true);

    expect($result['id'])->toBe(42);
});

test('risk is Read', function (): void {
    expect((new GetSeriesTool)->risk())->toBe(Risk::Read);
});
