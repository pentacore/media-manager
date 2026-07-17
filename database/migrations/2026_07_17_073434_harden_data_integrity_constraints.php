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
        // D-MED-1: the sidebar badge counts `action = 'played' AND updated_at
        // >= now()-10min` inside Inertia share() on every authenticated
        // request; neither column was indexed, so every navigation
        // seq-scanned an ever-growing table.
        Schema::table('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->index(['action', 'updated_at'], 'emby_activities_action_updated_at_index');
        });

        // D-MED-5: the Emby handler inserts `Item.Name ?? null`, but the
        // column was NOT NULL — a webhook without Item.Name became a
        // recurring constraint violation + failed job. Match series_title.
        Schema::table('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->string('media_title')->nullable()->change();
        });

        // Prior M3: usage dedupe relied on an exists()-then-create in
        // RecordAgentUsage with only a non-unique index — concurrent
        // delivery could double-bill an invocation. Duplicates (keep the
        // lowest id) are removed before the unique constraint lands.
        DB::statement('
            DELETE FROM ai_usage_records
            WHERE id NOT IN (
                SELECT MIN(id) FROM ai_usage_records GROUP BY invocation_id
            )
        ');
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->unique('invocation_id');
        });

        // D-LOW-4: persistConfirmedMatch() does updateOrCreate on
        // (anilist_id, user_confirmed) with no unique backing — double
        // submit created two confirmed rows and resolveMany() silently
        // picked one. Partial unique index covers exactly the lookup key.
        DB::statement('
            DELETE FROM anime_id_maps
            WHERE user_confirmed
              AND anilist_id IS NOT NULL
              AND id NOT IN (
                  SELECT MIN(id) FROM anime_id_maps
                  WHERE user_confirmed AND anilist_id IS NOT NULL
                  GROUP BY anilist_id
              )
        ');
        DB::statement('
            CREATE UNIQUE INDEX anime_id_maps_confirmed_anilist_unique
            ON anime_id_maps (anilist_id)
            WHERE user_confirmed
        ');

        // Prior M7: conversation ownership had no FK constraints. Orphans
        // are cleaned up first so the constraints can land.
        DB::statement('UPDATE agent_conversations SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)');
        DB::statement('UPDATE agent_conversation_messages SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)');
        DB::statement('DELETE FROM agent_conversation_messages WHERE conversation_id NOT IN (SELECT id FROM agent_conversations)');

        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('agent_conversation_messages', function (Blueprint $blueprint): void {
            $blueprint->foreign('conversation_id')->references('id')->on('agent_conversations')->cascadeOnDelete();
            $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $blueprint): void {
            $blueprint->dropForeign(['conversation_id']);
            $blueprint->dropForeign(['user_id']);
        });
        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->dropForeign(['user_id']);
        });

        DB::statement('DROP INDEX IF EXISTS anime_id_maps_confirmed_anilist_unique');

        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['invocation_id']);
        });

        Schema::table('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->string('media_title')->nullable(false)->change();
        });

        Schema::table('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('emby_activities_action_updated_at_index');
        });
    }
};
