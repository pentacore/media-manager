<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Models\AiModelPrice;
use App\Models\AiModelRateLimit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<AiModelRateLimit>
 */
class AiModelRateLimitFactory extends Factory
{
    #[Override]
    protected $model = AiModelRateLimit::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'ai_model_price_id' => AiModelPrice::factory(),
            'metric' => RateLimitMetric::Requests,
            'period' => RateLimitPeriod::Minute,
            'limit_value' => $this->faker->numberBetween(100, 100_000),
        ];
    }
}
