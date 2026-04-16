<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->string('id', 36)->primary();
            $blueprint->foreignId('user_id')->nullable();
            $blueprint->string('title');
            $blueprint->timestamps();

            $blueprint->index(['user_id', 'updated_at']);
        });

        Schema::create('agent_conversation_messages', function (Blueprint $blueprint): void {
            $blueprint->string('id', 36)->primary();
            $blueprint->string('conversation_id', 36)->index();
            $blueprint->foreignId('user_id')->nullable();
            $blueprint->string('agent');
            $blueprint->string('role', 25);
            $blueprint->text('content');
            $blueprint->text('attachments');
            $blueprint->text('tool_calls');
            $blueprint->text('tool_results');
            $blueprint->text('usage');
            $blueprint->text('meta');
            $blueprint->timestamps();

            $blueprint->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
            $blueprint->index(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_conversations');
        Schema::dropIfExists('agent_conversation_messages');
    }
};
