<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $blueprint): void {
            // activity | audit (App\Enums\ActivityLogCategory). Audit rows are
            // admin-only; the (category, created_at) index serves both the
            // filtered Activity log and the per-category retention prune.
            $blueprint->string('category')->default('activity');
            $blueprint->index(['category', 'created_at'], 'activity_logs_category_created_index');
        });

        // Every row written before the audit log existed is ordinary activity.
        DB::table('activity_logs')->whereNull('category')->update(['category' => 'activity']);
    }

    public function down(): void
    {
        // Without the column an audit row would read as ordinary activity and
        // show up for every role, so audit rows go with the column.
        DB::table('activity_logs')->where('category', 'audit')->delete();

        Schema::table('activity_logs', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('activity_logs_category_created_index');
            $blueprint->dropColumn('category');
        });
    }
};
