<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->index(['tier_position', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['tier_position', 'created_at']);
        });
    }
};
