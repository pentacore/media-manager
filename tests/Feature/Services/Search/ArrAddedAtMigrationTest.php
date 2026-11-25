<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use Illuminate\Support\Facades\DB;

function arrAddedAtMigration(): object
{
    return require database_path('migrations/2026_11_25_130000_store_arr_added_at_on_the_app_clock.php');
}

it('moves arr_added_at from the UTC wall clock to the app timezone wall clock and back', function (): void {
    config()->set('app.timezone', 'Europe/Stockholm');
    date_default_timezone_set('Europe/Stockholm');

    $indexedMovie = IndexedMovie::factory()->create();
    $indexedSeries = IndexedSeries::factory()->create();
    $unknown = IndexedMovie::factory()->create(['arr_added_at' => null]);

    // Rows as the old indexers wrote them: the UTC wall clock.
    DB::table('indexed_movies')->where('id', $indexedMovie->id)->update(['arr_added_at' => '2026-07-06 12:10:00']);
    DB::table('indexed_series')->where('id', $indexedSeries->id)->update(['arr_added_at' => '2026-01-15 23:30:00']);

    arrAddedAtMigration()->up();

    expect(DB::table('indexed_movies')->where('id', $indexedMovie->id)->value('arr_added_at'))->toBe('2026-07-06 14:10:00')
        ->and(DB::table('indexed_series')->where('id', $indexedSeries->id)->value('arr_added_at'))->toBe('2026-01-16 00:30:00')
        ->and(DB::table('indexed_movies')->where('id', $unknown->id)->value('arr_added_at'))->toBeNull();

    arrAddedAtMigration()->down();

    expect(DB::table('indexed_movies')->where('id', $indexedMovie->id)->value('arr_added_at'))->toBe('2026-07-06 12:10:00')
        ->and(DB::table('indexed_series')->where('id', $indexedSeries->id)->value('arr_added_at'))->toBe('2026-01-15 23:30:00')
        ->and(DB::table('indexed_movies')->where('id', $unknown->id)->value('arr_added_at'))->toBeNull();
});

it('leaves arr_added_at alone on a UTC install', function (): void {
    config()->set('app.timezone', 'UTC');

    $indexedMovie = IndexedMovie::factory()->create();
    DB::table('indexed_movies')->where('id', $indexedMovie->id)->update(['arr_added_at' => '2026-07-06 12:10:00']);

    arrAddedAtMigration()->up();

    expect(DB::table('indexed_movies')->where('id', $indexedMovie->id)->value('arr_added_at'))->toBe('2026-07-06 12:10:00');
});
