<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->string('kind')->default('text')->index();
            $blueprint->text('error_message')->nullable();
            $blueprint->string('parent_invocation_id')->nullable()->index();
            $blueprint->decimal('search_units', 14, 3)->default(0);
            $blueprint->decimal('search_unit_per_k', 12, 4)->nullable();
        });

        Schema::table('ai_tool_invocations', function (Blueprint $blueprint): void {
            $blueprint->unsignedInteger('duration_ms')->nullable();
            $blueprint->string('error_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_tool_invocations', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['duration_ms', 'error_code']);
        });

        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['kind']);
            $blueprint->dropIndex(['parent_invocation_id']);
            $blueprint->dropColumn(['kind', 'error_message', 'parent_invocation_id', 'search_units', 'search_unit_per_k']);
        });
    }
};
