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
        Schema::table('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->string('play_session_id')->nullable()->after('emby_item_id');
            $blueprint->index('play_session_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX emby_activities_session_unique '
            .'ON emby_activities (emby_user_link_id, emby_item_id, play_session_id) '
            .'WHERE play_session_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS emby_activities_session_unique');

        Schema::table('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['play_session_id']);
            $blueprint->dropColumn('play_session_id');
        });
    }
};
