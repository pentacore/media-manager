<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['agent_conversations', 'chat_templates'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('model_provider', 40)->nullable();
                $blueprint->string('model', 100)->nullable();
                $blueprint->string('reasoning', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['agent_conversations', 'chat_templates'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['model_provider', 'model', 'reasoning']);
            });
        }
    }
};
