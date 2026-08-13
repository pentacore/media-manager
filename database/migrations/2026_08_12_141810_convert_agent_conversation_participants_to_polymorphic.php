<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * laravel/ai 0.10 stores conversation participants polymorphically
     * (participant_type / participant_id) instead of a users FK, so the
     * FK constraints from the data-integrity hardening migration have to
     * go — a polymorphic column cannot carry one.
     */
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->dropForeign(['user_id']);
            $blueprint->dropIndex(['user_id', 'updated_at']);
            $blueprint->renameColumn('user_id', 'participant_id');
            $blueprint->string('participant_type')->nullable();
        });

        Schema::table('agent_conversation_messages', function (Blueprint $blueprint): void {
            $blueprint->dropForeign(['user_id']);
            $blueprint->dropIndex('conversation_index');
            $blueprint->dropIndex(['user_id']);
            $blueprint->renameColumn('user_id', 'participant_id');
            $blueprint->string('participant_type')->nullable();
            $blueprint->text('approval_state')->nullable();
        });

        DB::table('agent_conversations')
            ->whereNotNull('participant_id')
            ->update(['participant_type' => (new User)->getMorphClass()]);
        DB::table('agent_conversation_messages')
            ->whereNotNull('participant_id')
            ->update(['participant_type' => (new User)->getMorphClass()]);

        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $blueprint): void {
            $blueprint->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $blueprint->index(['participant_type', 'participant_id'], 'participant_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('conversation_index');
            $blueprint->dropIndex('participant_index');
            $blueprint->dropColumn(['participant_type', 'approval_state']);
            $blueprint->renameColumn('participant_id', 'user_id');
        });

        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('participant_updated_at_index');
            $blueprint->dropColumn('participant_type');
            $blueprint->renameColumn('participant_id', 'user_id');
        });

        DB::statement('UPDATE agent_conversations SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)');
        DB::statement('UPDATE agent_conversation_messages SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)');

        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->index(['user_id', 'updated_at']);
            $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('agent_conversation_messages', function (Blueprint $blueprint): void {
            $blueprint->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
            $blueprint->index(['user_id']);
            $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
