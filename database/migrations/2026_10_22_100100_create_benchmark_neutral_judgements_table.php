<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_neutral_judgements', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('benchmark_result_id')->constrained()->cascadeOnDelete();
            $blueprint->foreignId('benchmark_neutral_rejudge_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('judge_key');
            $blueprint->json('judge');
            $blueprint->string('status')->default('queued');
            $blueprint->float('score')->nullable();
            $blueprint->boolean('passed')->nullable();
            $blueprint->json('detail')->nullable();
            $blueprint->decimal('cost_usd', 12, 6)->default(0);
            $blueprint->timestamps();

            $blueprint->unique(['benchmark_result_id', 'judge_key']);
            $blueprint->index(['judge_key', 'status']);
            $blueprint->index(['benchmark_neutral_rejudge_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_neutral_judgements');
    }
};
