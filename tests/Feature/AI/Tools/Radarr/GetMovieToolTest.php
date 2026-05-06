<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Radarr\GetMovieTool;
use App\Models\IndexedMovie;
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

test('lists indexed movies with slim projection when no id is given', function (): void {
    IndexedMovie::factory()->for($this->connection, 'serviceConnection')->create([
        'radarr_id' => 7,
        'title' => 'Alpha',
        'monitored' => true,
        'has_file' => true,
        'status' => 'released',
    ]);

    $result = json_decode((new GetMovieTool)->handle(new Request(['movie_id' => null])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['returned'])->toBe(1);
    expect($result['movies'][0]['id'])->toBe(7);
    expect($result['movies'][0]['title'])->toBe('Alpha');
    expect($result['movies'][0])->toHaveKeys([
        'id', 'tmdb_id', 'title', 'year', 'status', 'monitored', 'has_file', 'genres',
    ]);
    expect($result['movies'][0])->not->toHaveKey('overview');
});

test('count_only returns aggregate counts only', function (): void {
    IndexedMovie::factory()->count(3)->for($this->connection, 'serviceConnection')->create(['monitored' => true, 'has_file' => true]);
    IndexedMovie::factory()->count(2)->for($this->connection, 'serviceConnection')->create(['monitored' => false, 'has_file' => false]);

    $result = json_decode((new GetMovieTool)->handle(new Request([
        'movie_id' => null,
        'count_only' => true,
        'has_file' => false,
    ])), true);

    expect($result['matched'])->toBe(2);
    expect($result['library_total'])->toBe(5);
    expect($result['library_monitored'])->toBe(3);
    expect($result['library_unmonitored'])->toBe(2);
    expect($result['library_with_file'])->toBe(3);
    expect($result['library_without_file'])->toBe(2);
    expect($result)->not->toHaveKey('movies');
});

test('has_file filter narrows the list', function (): void {
    IndexedMovie::factory()->for($this->connection, 'serviceConnection')->create(['title' => 'Have', 'has_file' => true]);
    IndexedMovie::factory()->for($this->connection, 'serviceConnection')->create(['title' => 'Missing', 'has_file' => false]);

    $result = json_decode((new GetMovieTool)->handle(new Request([
        'movie_id' => null,
        'has_file' => false,
    ])), true);

    expect($result['total_matched'])->toBe(1);
    expect($result['movies'][0]['title'])->toBe('Missing');
});

test('returns single movie from Radarr when id is given', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/42' => Http::response(['id' => 42, 'title' => 'Demo']),
    ]);

    $result = json_decode((new GetMovieTool)->handle(new Request(['movie_id' => 42])), true);

    expect($result['id'])->toBe(42);
});

test('risk is Read', function (): void {
    expect((new GetMovieTool)->risk())->toBe(Risk::Read);
});
