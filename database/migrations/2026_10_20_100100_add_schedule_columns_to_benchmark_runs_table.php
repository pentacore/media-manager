<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_runs', function (Blueprint $blueprint): void {
            $blueprint->foreignId('benchmark_schedule_id')->nullable()->index()->constrained()->nullOnDelete();
            $blueprint->foreignId('baseline_run_id')->nullable()->constrained('benchmark_runs')->nullOnDelete();
            $blueprint->jsonb('regressions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_runs', function (Blueprint $blueprint): void {
            $blueprint->dropConstrainedForeignId('baseline_run_id');
            $blueprint->dropConstrainedForeignId('benchmark_schedule_id');
            $blueprint->dropColumn('regressions');
        });
    }
};
