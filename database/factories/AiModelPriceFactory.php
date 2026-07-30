<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModelPrice>
 */
class AiModelPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => fake()->randomElement(['openai', 'anthropic', 'google', 'deepseek']),
            'model' => 'test-'.fake()->uuid(),
            'input_per_mtok' => fake()->randomFloat(4, 0.1, 10.0),
            'output_per_mtok' => fake()->randomFloat(4, 0.5, 30.0),
            'cache_read_per_mtok' => 0,
            'cache_write_per_mtok' => 0,
            'reasoning_per_mtok' => 0,
            'batch_input_per_mtok' => 0,
            'batch_output_per_mtok' => 0,
            'batch_cache_read_per_mtok' => 0,
            'batch_cache_write_per_mtok' => 0,
            'batch_reasoning_per_mtok' => 0,
            'pricing_source' => PricingSource::Legacy,
            'is_price_locked' => false,
        ];
    }
}
