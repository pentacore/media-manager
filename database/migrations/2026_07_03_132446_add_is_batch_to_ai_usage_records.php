<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds an `is_batch` flag so a usage row can record that its run was
     * priced against the provider's batch tier. Accounting groundwork only:
     * nothing sets this to true yet (the AI SDK has no batch API). When a
     * future batch pipeline lands — e.g. embedding backfills routed through a
     * provider batch endpoint — the flag lets RecordAgentUsage snapshot the
     * `batch_*_per_mtok` rates and downstream reporting costs it correctly
     * with no further change.
     */
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->boolean('is_batch')->default(false)->after('reasoning_per_mtok');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('is_batch');
        });
    }
};
