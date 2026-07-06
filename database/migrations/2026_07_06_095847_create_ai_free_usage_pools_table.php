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
        Schema::create('ai_free_usage_pools', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('name')->unique();
            $blueprint->string('period')->default('monthly');
            // Unified pools pull input + output from free_total_tokens;
            // split pools use the input/output pair. Null = no cap on
            // that dimension.
            $blueprint->boolean('unified')->default(false);
            $blueprint->unsignedBigInteger('free_input_tokens')->nullable();
            $blueprint->unsignedBigInteger('free_output_tokens')->nullable();
            $blueprint->unsignedBigInteger('free_total_tokens')->nullable();
            $blueprint->string('documentation_url')->nullable();
            $blueprint->timestamps();
        });

        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->foreignId('free_usage_pool_id')
                ->nullable()
                ->constrained('ai_free_usage_pools')
                ->nullOnDelete();
        });

        // Convert each existing per-row free tier into its own
        // single-member monthly pool so no configured quota is lost.
        $rows = DB::table('ai_model_prices')
            ->where(function ($query): void {
                $query->whereNotNull('free_input_tokens_per_month')
                    ->orWhereNotNull('free_output_tokens_per_month');
            })
            ->get(['id', 'provider', 'model', 'free_input_tokens_per_month', 'free_output_tokens_per_month']);

        foreach ($rows as $row) {
            $poolId = DB::table('ai_free_usage_pools')->insertGetId([
                'name' => $row->provider.' '.$row->model,
                'period' => 'monthly',
                'unified' => false,
                'free_input_tokens' => $row->free_input_tokens_per_month,
                'free_output_tokens' => $row->free_output_tokens_per_month,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ai_model_prices')->where('id', $row->id)->update([
                'free_usage_pool_id' => $poolId,
            ]);
        }

        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['free_input_tokens_per_month', 'free_output_tokens_per_month']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('free_input_tokens_per_month')->nullable()->after('reasoning_per_mtok');
            $blueprint->unsignedBigInteger('free_output_tokens_per_month')->nullable()->after('free_input_tokens_per_month');
        });

        // Best-effort restore for single-member split pools (the only shape
        // the up() conversion produces).
        $prices = DB::table('ai_model_prices')
            ->whereNotNull('free_usage_pool_id')
            ->get(['id', 'free_usage_pool_id']);

        foreach ($prices as $price) {
            $pool = DB::table('ai_free_usage_pools')->find($price->free_usage_pool_id);

            if ($pool === null || (bool) $pool->unified) {
                continue;
            }

            DB::table('ai_model_prices')->where('id', $price->id)->update([
                'free_input_tokens_per_month' => $pool->free_input_tokens,
                'free_output_tokens_per_month' => $pool->free_output_tokens,
            ]);
        }

        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->dropConstrainedForeignId('free_usage_pool_id');
        });

        Schema::drop('ai_free_usage_pools');
    }
};
