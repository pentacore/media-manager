<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_schedules', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('name', 160);
            $blueprint->string('task', 40);
            $blueprint->jsonb('case_tags')->default('[]');
            $blueprint->jsonb('judge');
            $blueprint->decimal('cost_cap_usd', 12, 6);
            $blueprint->string('frequency', 20);
            $blueprint->string('time_of_day', 5);
            $blueprint->unsignedSmallInteger('weekday')->nullable();
            $blueprint->unsignedSmallInteger('day_of_month')->nullable();
            $blueprint->decimal('pass_rate_threshold', 5, 2)->default(10);
            $blueprint->decimal('score_threshold', 5, 2)->default(10);
            $blueprint->boolean('enabled')->default(true);
            $blueprint->timestamp('next_run_at')->nullable()->index();
            $blueprint->timestamp('last_run_at')->nullable();
            $blueprint->string('last_outcome', 30)->nullable();
            $blueprint->string('last_message')->nullable();
            $blueprint->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_schedules');
    }
};
