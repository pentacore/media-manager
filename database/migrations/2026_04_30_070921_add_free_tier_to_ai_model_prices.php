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
            // Per-month "ignore the first N tokens" budget. Most providers
            // (Gemini, Groq) gate their free tier per-model rather than
            // per-account, and the values differ across siblings (e.g.
            // gemini-2.5-flash vs gemini-2.5-pro), so we store the cap on
            // the price row itself. Null = no free tier.
            $blueprint->unsignedBigInteger('free_input_tokens_per_month')->nullable()->after('reasoning_per_mtok');
            $blueprint->unsignedBigInteger('free_output_tokens_per_month')->nullable()->after('free_input_tokens_per_month');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['free_input_tokens_per_month', 'free_output_tokens_per_month']);
        });
    }
};
