<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_rate_limits', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('ai_model_price_id')
                ->constrained('ai_model_prices')
                ->cascadeOnDelete();
            $blueprint->string('metric');
            $blueprint->string('period');
            $blueprint->unsignedBigInteger('limit_value');
            $blueprint->timestamps();

            // One limit per (model, metric, window) — "500 requests/min"
            // and "100k tokens/min" can coexist, a second requests/min
            // row cannot.
            $blueprint->unique(['ai_model_price_id', 'metric', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_rate_limits');
    }
};
