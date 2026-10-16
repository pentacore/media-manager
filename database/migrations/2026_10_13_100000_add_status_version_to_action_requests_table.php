<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * action_requests.status_version and the sequence it is taken from. PHP
 * numbers every create and every status change (ActionRequest::
 * nextStatusVersion(), through ActionRequestStatusVersionObserver or the
 * query-builder writer itself). The column default numbers an insert that
 * bypasses Eloquent, including from code still running right after this
 * migration. Existing rows are numbered by when they last changed
 * (updated_at, then id); the sequence continues above the highest.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE action_request_status_versions');

        Schema::table('action_requests', function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('status_version')->nullable()->after('status');
        });

        DB::statement(<<<'SQL'
            UPDATE action_requests
            SET status_version = numbered.version
            FROM (
                SELECT id, row_number() OVER (ORDER BY updated_at NULLS FIRST, id) AS version
                FROM action_requests
            ) AS numbered
            WHERE action_requests.id = numbered.id
            SQL);

        DB::statement(<<<'SQL'
            SELECT setval(
                'action_request_status_versions',
                GREATEST((SELECT COALESCE(MAX(status_version), 0) FROM action_requests), 1),
                (SELECT COUNT(*) > 0 FROM action_requests)
            )
            SQL);

        DB::statement("ALTER TABLE action_requests ALTER COLUMN status_version SET DEFAULT nextval('action_request_status_versions')");
        DB::statement('ALTER TABLE action_requests ALTER COLUMN status_version SET NOT NULL');
        DB::statement('ALTER SEQUENCE action_request_status_versions OWNED BY action_requests.status_version');

        Schema::table('action_requests', function (Blueprint $blueprint): void {
            $blueprint->index('status_version');
        });
    }

    public function down(): void
    {
        Schema::table('action_requests', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['status_version']);
            // Dropping the column drops the sequence it owns.
            $blueprint->dropColumn('status_version');
        });

        DB::statement('DROP SEQUENCE IF EXISTS action_request_status_versions');
    }
};
