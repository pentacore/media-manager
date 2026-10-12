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
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->boolean('is_benchmark')->default(false)->index();
        });

        DB::table('ai_usage_records')->whereNotNull('benchmark_run_id')->update(['is_benchmark' => true]);
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['is_benchmark']);
            $blueprint->dropColumn('is_benchmark');
        });
    }
};
