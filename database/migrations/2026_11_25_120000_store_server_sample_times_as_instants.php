<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stores the host monitoring samples' `recorded_at` as `timestamptz` instead of zoneless app-timezone wall clock, so the
 * two 02:00–03:00 hours of a DST fall-back night are different rows instead of the second overwriting the first.
 *
 * Existing rows are read as app-timezone wall clock. A row from a past fall-back hour cannot be told apart (the second
 * hour already overwrote the first), so Postgres places it in the later, standard-time hour. Going back down, the later of two
 * rows that land on the same wall clock is kept, as the old write did.
 */
return new class extends Migration
{
    /** @var array<string, string> table => the unique key's columns before `recorded_at` */
    private const array TABLES = [
        'server_metric_samples' => 'server_id, resolution',
        'server_filesystem_samples' => 'server_id, resolution, mount',
    ];

    public function up(): void
    {
        $timezone = DB::escape((string) config('app.timezone'));

        foreach (array_keys(self::TABLES) as $table) {
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN recorded_at TYPE timestamptz(0) USING recorded_at AT TIME ZONE %s',
                $table,
                $timezone,
            ));
        }
    }

    public function down(): void
    {
        $timezone = DB::escape((string) config('app.timezone'));

        foreach (self::TABLES as $table => $keyColumns) {
            $sameKey = implode(' AND ', array_map(
                static fn (string $column): string => sprintf('later.%1$s = earlier.%1$s', $column),
                explode(', ', $keyColumns),
            ));

            DB::statement(sprintf(
                'DELETE FROM %1$s AS earlier USING %1$s AS later WHERE %2$s AND later.recorded_at > earlier.recorded_at'
                .' AND (later.recorded_at AT TIME ZONE %3$s) = (earlier.recorded_at AT TIME ZONE %3$s)',
                $table,
                $sameKey,
                $timezone,
            ));
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN recorded_at TYPE timestamp(0) without time zone USING recorded_at AT TIME ZONE %s',
                $table,
                $timezone,
            ));
        }
    }
};
