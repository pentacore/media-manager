<?php

declare(strict_types=1);

use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Models\AiModelPrice;
use App\Models\AiModelRateLimit;
use Illuminate\Database\QueryException;

test('a price can have rate limits and deleting the price cascades', function (): void {
    $price = AiModelPrice::factory()->create();

    $limit = AiModelRateLimit::factory()->create([
        'ai_model_price_id' => $price->id,
        'metric' => RateLimitMetric::Requests,
        'period' => RateLimitPeriod::Minute,
        'limit_value' => 500,
    ]);

    expect($price->rateLimits()->count())->toBe(1)
        ->and($limit->price->id)->toBe($price->id)
        ->and($limit->metric)->toBe(RateLimitMetric::Requests)
        ->and($limit->period)->toBe(RateLimitPeriod::Minute)
        ->and($limit->limit_value)->toBe(500);

    $price->delete();

    expect(AiModelRateLimit::query()->count())->toBe(0);
});

test('duplicate metric+period for the same price is rejected by the unique index', function (): void {
    $price = AiModelPrice::factory()->create();

    AiModelRateLimit::factory()->create([
        'ai_model_price_id' => $price->id,
        'metric' => RateLimitMetric::Tokens,
        'period' => RateLimitPeriod::Day,
    ]);

    expect(fn () => AiModelRateLimit::factory()->create([
        'ai_model_price_id' => $price->id,
        'metric' => RateLimitMetric::Tokens,
        'period' => RateLimitPeriod::Day,
    ]))->toThrow(QueryException::class);
});
