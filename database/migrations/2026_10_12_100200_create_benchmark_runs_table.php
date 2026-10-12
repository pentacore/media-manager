<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_runs', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('name', 160);
            $blueprint->string('status', 30)->default('pending')->index();
            $blueprint->jsonb('candidates');
            $blueprint->jsonb('judge')->nullable();
            $blueprint->jsonb('case_ids');
            $blueprint->decimal('estimate_usd', 12, 6)->default(0);
            $blueprint->decimal('cost_cap_usd', 12, 6)->nullable();
            $blueprint->decimal('cost_usd', 12, 6)->default(0);
            $blueprint->timestamp('started_at')->nullable();
            $blueprint->timestamp('finished_at')->nullable();
            $blueprint->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_runs');
    }
};
