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
            $blueprint->decimal('search_unit_per_k', 12, 4)->default(0);
            $blueprint->decimal('batch_search_unit_per_k', 12, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['search_unit_per_k', 'batch_search_unit_per_k']);
        });
    }
};
