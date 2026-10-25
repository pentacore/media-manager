<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_templates', function (Blueprint $blueprint): void {
            $blueprint->json('tool_groups')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_templates', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('tool_groups');
        });
    }
};
