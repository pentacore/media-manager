<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_free_usage_pools', function (Blueprint $blueprint): void {
            // fit_or_paid = OpenAI-style: a request only draws from the pool
            // when it fits the remaining quota in full, otherwise the whole
            // request is billed. split = only the overage is billed.
            $blueprint->string('overflow_behavior')->default('fit_or_paid')->after('free_total_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_free_usage_pools', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('overflow_behavior');
        });
    }
};
