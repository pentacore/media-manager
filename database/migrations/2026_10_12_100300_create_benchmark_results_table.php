<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_results', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('benchmark_run_id')->constrained()->cascadeOnDelete();
            $blueprint->foreignId('benchmark_case_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('case_name', 160);
            $blueprint->string('case_task', 40);
            $blueprint->unsignedSmallInteger('candidate_index');
            $blueprint->string('status', 20)->default('pending');
            $blueprint->text('output')->nullable();
            $blueprint->jsonb('intended_actions')->default('[]');
            $blueprint->unsignedInteger('prompt_tokens')->default(0);
            $blueprint->unsignedInteger('completion_tokens')->default(0);
            $blueprint->decimal('cost_usd', 12, 6)->default(0);
            $blueprint->unsignedInteger('latency_ms')->nullable();
            $blueprint->unsignedSmallInteger('tool_calls')->default(0);
            $blueprint->unsignedSmallInteger('tool_misses')->default(0);
            $blueprint->text('error')->nullable();
            $blueprint->decimal('score', 5, 4)->nullable();
            $blueprint->boolean('passed')->nullable();
            $blueprint->jsonb('score_detail')->nullable();
            $blueprint->string('judge_status', 20)->default('none');
            $blueprint->decimal('judge_cost_usd', 12, 6)->default(0);
            $blueprint->timestamps();

            $blueprint->index(['benchmark_run_id', 'case_task']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_results');
    }
};
