<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_tool_invocations', function (Blueprint $blueprint): void {
            $blueprint->string('tool_name', 120)->nullable();
            $blueprint->jsonb('arguments')->nullable();
            $blueprint->text('result')->nullable();
            $blueprint->boolean('io_truncated')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ai_tool_invocations', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['tool_name', 'arguments', 'result', 'io_truncated']);
        });
    }
};
