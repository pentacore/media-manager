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
            $blueprint->boolean('supports_reasoning')->nullable();
            $blueprint->json('reasoning_levels')->nullable();
            $blueprint->string('reasoning_style', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['supports_reasoning', 'reasoning_levels', 'reasoning_style']);
        });
    }
};
