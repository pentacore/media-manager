<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_neutral_rejudges', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->json('run_ids');
            $blueprint->json('judge');
            $blueprint->string('judge_key');
            $blueprint->string('status')->default('running');
            $blueprint->decimal('estimate_usd', 12, 6)->default(0);
            $blueprint->decimal('cost_cap_usd', 12, 6);
            $blueprint->decimal('cost_usd', 12, 6)->default(0);
            $blueprint->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->timestamp('started_at')->nullable();
            $blueprint->timestamp('finished_at')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_neutral_rejudges');
    }
};
