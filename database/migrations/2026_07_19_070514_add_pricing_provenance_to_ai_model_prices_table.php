<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            // Provenance and locking metadata so syncs from the Models.dev
            // feed, manual admin overrides, and agent verification can reason
            // about the trust and freshness of each stored price.
            $blueprint->string('pricing_source')->nullable()->after('free_usage_pool_id');
            $blueprint->text('pricing_source_url')->nullable()->after('pricing_source');
            $blueprint->date('pricing_source_updated_at')->nullable()->after('pricing_source_url');
            $blueprint->timestamp('pricing_synced_at')->nullable()->after('pricing_source_updated_at');
            $blueprint->timestamp('pricing_verified_at')->nullable()->after('pricing_synced_at');
            $blueprint->boolean('is_price_locked')->default(false)->after('pricing_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $blueprint): void {
            $blueprint->dropColumn([
                'pricing_source',
                'pricing_source_url',
                'pricing_source_updated_at',
                'pricing_synced_at',
                'pricing_verified_at',
                'is_price_locked',
            ]);
        });
    }
};
