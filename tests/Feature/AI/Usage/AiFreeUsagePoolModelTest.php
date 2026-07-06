<?php

declare(strict_types=1);

use App\Enums\FreeUsagePeriod;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use Illuminate\Support\Facades\Schema;

test('a pool exposes its member prices and casts period to the enum', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'name' => 'Gemini free tier',
        'period' => FreeUsagePeriod::Weekly,
        'unified' => true,
        'free_total_tokens' => 1_000_000,
    ]);

    $price = AiModelPrice::factory()->create(['free_usage_pool_id' => $pool->id]);

    expect($pool->period)->toBe(FreeUsagePeriod::Weekly);
    expect($pool->unified)->toBeTrue();
    expect($pool->prices)->toHaveCount(1);
    expect($pool->prices->first()->is($price))->toBeTrue();
    expect($price->freeUsagePool->is($pool))->toBeTrue();
});

test('deleting a pool nulls the member foreign keys instead of deleting prices', function (): void {
    $pool = AiFreeUsagePool::factory()->create();
    $price = AiModelPrice::factory()->create(['free_usage_pool_id' => $pool->id]);

    $pool->delete();

    expect($price->fresh()->free_usage_pool_id)->toBeNull();
    $this->assertDatabaseHas('ai_model_prices', ['id' => $price->id]);
});

test('the old per-row free tier columns are gone', function (): void {
    expect(Schema::hasColumn('ai_model_prices', 'free_input_tokens_per_month'))->toBeFalse();
    expect(Schema::hasColumn('ai_model_prices', 'free_output_tokens_per_month'))->toBeFalse();
    expect(Schema::hasColumn('ai_model_prices', 'free_usage_pool_id'))->toBeTrue();
});
