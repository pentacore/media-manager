<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves `indexed_movies.arr_added_at` and `indexed_series.arr_added_at` from the UTC wall clock (the indexers used to
 * write the *arr "added" instant unconverted) to the app-timezone wall clock every other zoneless column holds. A no-op
 * in effect on a UTC install.
 */
return new class extends Migration
{
    private const array TABLES = ['indexed_movies', 'indexed_series'];

    public function up(): void
    {
        $this->shift('UTC', (string) config('app.timezone'));
    }

    public function down(): void
    {
        $this->shift((string) config('app.timezone'), 'UTC');
    }

    /**
     * Reads each value as a wall clock on `$from` and rewrites it as the same instant's wall clock on `$to`.
     */
    private function shift(string $from, string $to): void
    {
        foreach (self::TABLES as $table) {
            DB::statement(sprintf(
                'UPDATE %s SET arr_added_at = (arr_added_at AT TIME ZONE %s) AT TIME ZONE %s WHERE arr_added_at IS NOT NULL',
                $table,
                DB::escape($from),
                DB::escape($to),
            ));
        }
    }
};
