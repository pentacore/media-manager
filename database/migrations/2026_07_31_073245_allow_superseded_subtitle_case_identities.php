<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A resolved identity whose subtitles go missing again has to be superseded and
     * observed as a fresh case, otherwise the terminal row keeps the file out of
     * subtitle automation forever. That requires the material identity to be unique
     * only among cases still on the record, so superseded rows leave the index.
     */
    public function up(): void
    {
        // Dropped through the schema builder because the original index backs a
        // unique constraint on Postgres and a plain index on SQLite.
        Schema::table('subtitle_cases', function (Blueprint $blueprint): void {
            $blueprint->dropUnique('subtitle_cases_material_identity_unique');
        });

        DB::statement("
            CREATE UNIQUE INDEX subtitle_cases_material_identity_unique
            ON subtitle_cases (
                bazarr_connection_id,
                service_connection_id,
                file_fingerprint,
                requirements_fingerprint
            )
            WHERE status <> 'superseded'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS subtitle_cases_material_identity_unique');

        Schema::table('subtitle_cases', function (Blueprint $blueprint): void {
            $blueprint->unique([
                'bazarr_connection_id',
                'service_connection_id',
                'file_fingerprint',
                'requirements_fingerprint',
            ], 'subtitle_cases_material_identity_unique');
        });
    }
};
