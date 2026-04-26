<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('provider');
            $blueprint->string('model');
            $blueprint->decimal('input_per_mtok', 10, 4)->default(0);
            $blueprint->decimal('output_per_mtok', 10, 4)->default(0);
            $blueprint->decimal('cache_read_per_mtok', 10, 4)->default(0);
            $blueprint->decimal('cache_write_per_mtok', 10, 4)->default(0);
            $blueprint->decimal('reasoning_per_mtok', 10, 4)->default(0);
            $blueprint->timestamps();

            $blueprint->unique(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
    }
};
