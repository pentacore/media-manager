<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns each task scope's single selection into an ordered tier list.
 * Existing rows become position 0 with no pool conditions.
 *
 * Decision event rows with a model and no reasoning used to borrow the
 * Decision default row's reasoning. Tiers resolve reasoning per tier, so that
 * reasoning is copied onto them to keep their resolved level unchanged. The
 * copy stays on down(): under the old rules it resolves to the same level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_task_models', function (Blueprint $blueprint): void {
            $blueprint->unsignedSmallInteger('position')->default(0);
            $blueprint->unsignedSmallInteger('min_pool_percent')->nullable();
            $blueprint->unsignedBigInteger('min_pool_tokens')->nullable();
            $blueprint->dropUnique(['task', 'scope']);
            $blueprint->unique(['task', 'scope', 'position']);
        });

        $decisionReasoning = DB::table('ai_task_models')
            ->where('task', 'decision')
            ->where('scope', 'default')
            ->value('reasoning');

        if ($decisionReasoning === null) {
            return;
        }

        DB::table('ai_task_models')
            ->where('task', 'decision')
            ->where('scope', '!=', 'default')
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->whereNull('reasoning')
            ->update(['reasoning' => $decisionReasoning, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('ai_task_models')->where('position', '>', 0)->delete();

        Schema::table('ai_task_models', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['task', 'scope', 'position']);
            $blueprint->unique(['task', 'scope']);
            $blueprint->dropColumn(['position', 'min_pool_percent', 'min_pool_tokens']);
        });
    }
};
