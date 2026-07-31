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

    /**
     * Reversible only while no identity has actually been reopened. Once a
     * superseded row and a live row share an identity — exactly what the partial
     * index exists to allow — the full constraint cannot be restored, and the only
     * ways to force it would be destroying case history or rewriting fingerprints
     * that reconciliation derives from the media file. Both are worse than an
     * explicit stop, so the duplicates are reported and the rollback refuses.
     */
    public function down(): void
    {
        $duplicateIdentities = DB::table('subtitle_cases')
            ->selectRaw('bazarr_connection_id, service_connection_id, file_fingerprint, requirements_fingerprint, count(*) as total')
            ->groupBy('bazarr_connection_id', 'service_connection_id', 'file_fingerprint', 'requirements_fingerprint')
            ->havingRaw('count(*) > 1')
            ->count();

        throw_if($duplicateIdentities > 0, RuntimeException::class, sprintf(
            'Cannot restore the full subtitle_cases material-identity constraint: %d identities have been reopened and now have superseded history. Resolve or delete the superseded rows for those identities first.',
            $duplicateIdentities,
        ));

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
