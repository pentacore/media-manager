<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Search\MovieIndexer;
use App\Services\Search\SeriesIndexer;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('app.timezone', 'Europe/Stockholm');
    date_default_timezone_set('Europe/Stockholm');
});

it('stores a movie\'s *arr "added" instant on the app timezone wall clock', function (): void {
    $serviceConnection = ServiceConnection::factory()->radarr()->create();

    $indexedMovie = resolve(MovieIndexer::class)->upsert(
        ['id' => 7, 'title' => 'Example Movie', 'added' => '2026-07-06T12:10:00Z'],
        $serviceConnection,
    );

    // 12:10 UTC is 14:10 in Stockholm (UTC+2 in July).
    expect(DB::table('indexed_movies')->where('id', $indexedMovie->id)->value('arr_added_at'))->toBe('2026-07-06 14:10:00')
        ->and(IndexedMovie::query()->findOrFail($indexedMovie->id)->arr_added_at?->utc()->toIso8601String())->toBe('2026-07-06T12:10:00+00:00');
});

it('stores a series\' *arr "added" instant on the app timezone wall clock', function (): void {
    $serviceConnection = ServiceConnection::factory()->sonarr()->create();

    $indexedSeries = resolve(SeriesIndexer::class)->upsert(
        ['id' => 9, 'title' => 'Example Series', 'added' => '2026-01-15T23:30:00Z'],
        $serviceConnection,
    );

    // 23:30 UTC on the 15th is 00:30 on the 16th in Stockholm (UTC+1 in January).
    expect(DB::table('indexed_series')->where('id', $indexedSeries->id)->value('arr_added_at'))->toBe('2026-01-16 00:30:00')
        ->and(IndexedSeries::query()->findOrFail($indexedSeries->id)->arr_added_at?->utc()->toIso8601String())->toBe('2026-01-15T23:30:00+00:00');
});
