<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "resolved" push is held until an incident stayed resolved for a while; this records when it went out. Incidents
 * resolved before the column existed already got their push, so they are backfilled as sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_incidents', function (Blueprint $blueprint): void {
            $blueprint->timestamp('resolve_notified_at')->nullable();
        });

        DB::table('server_incidents')
            ->where('status', 'resolved')
            ->update(['resolve_notified_at' => DB::raw('resolved_at')]);

        DB::statement("CREATE INDEX server_incidents_resolve_pending_index ON server_incidents (resolved_at) WHERE status = 'resolved' AND resolve_notified_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS server_incidents_resolve_pending_index');

        Schema::table('server_incidents', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('resolve_notified_at');
        });
    }
};
