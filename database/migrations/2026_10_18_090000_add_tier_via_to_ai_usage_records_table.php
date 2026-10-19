<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The label of the list a run followed when its tier came from another task's
 * list (e.g. "Chat"); null when the run used its own list or no tiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->string('tier_via', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('tier_via');
        });
    }
};
