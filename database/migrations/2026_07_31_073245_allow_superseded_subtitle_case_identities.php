<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        // The original index backs a unique constraint, which owns it.
        DB::statement('ALTER TABLE subtitle_cases DROP CONSTRAINT IF EXISTS subtitle_cases_material_identity_unique');
        DB::statement('DROP INDEX IF EXISTS subtitle_cases_material_identity_unique');
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
        DB::statement('
            ALTER TABLE subtitle_cases
            ADD CONSTRAINT subtitle_cases_material_identity_unique UNIQUE (
                bazarr_connection_id,
                service_connection_id,
                file_fingerprint,
                requirements_fingerprint
            )
        ');
    }
};
