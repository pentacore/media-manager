<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_outcomes', function (Blueprint $blueprint): void {
            $blueprint->string('outcome_source')->nullable()->after('outcome_detail');
        });

        DB::table('classification_outcomes')
            ->whereNotNull('outcome_at')
            ->update(['outcome_source' => DB::raw(<<<'SQL'
                CASE
                    WHEN gate = 'second_opinion' AND outcome_detail IN ('the LLM verifier agreed', 'the LLM verifier disagreed') THEN 'llm'
                    WHEN gate = 'second_opinion' AND outcome_detail IN ('approved by a reviewer', 'rejected by a reviewer') THEN 'reviewer'
                    ELSE 'event'
                END
                SQL)]);
    }

    public function down(): void
    {
        Schema::table('classification_outcomes', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('outcome_source');
        });
    }
};
