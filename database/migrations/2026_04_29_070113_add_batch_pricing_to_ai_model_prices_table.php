<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->decimal('batch_input_per_mtok', 10, 4)->nullable()->after('reasoning_per_mtok');
            $blueprint->decimal('batch_output_per_mtok', 10, 4)->nullable()->after('batch_input_per_mtok');
            $blueprint->decimal('batch_cache_read_per_mtok', 10, 4)->nullable()->after('batch_output_per_mtok');
            $blueprint->decimal('batch_cache_write_per_mtok', 10, 4)->nullable()->after('batch_cache_read_per_mtok');
            $blueprint->decimal('batch_reasoning_per_mtok', 10, 4)->nullable()->after('batch_cache_write_per_mtok');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->dropColumn([
                'batch_input_per_mtok',
                'batch_output_per_mtok',
                'batch_cache_read_per_mtok',
                'batch_cache_write_per_mtok',
                'batch_reasoning_per_mtok',
            ]);
        });
    }
};
