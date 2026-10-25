<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->json('pinned_tool_groups')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('pinned_tool_groups');
        });
    }
};
