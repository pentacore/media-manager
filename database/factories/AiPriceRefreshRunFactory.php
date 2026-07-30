<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiPriceRefreshRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPriceRefreshRun>
 */
class AiPriceRefreshRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mode' => fake()->randomElement(['full', 'single']),
            'trigger' => fake()->randomElement(['scheduled', 'manual', 'cli']),
            'triggered_by_user_id' => null,
            'status' => fake()->randomElement(['pending', 'running', 'completed', 'failed']),
            'models_dev_status' => null,
            'providers_requested' => 0,
            'providers_succeeded' => 0,
            'providers_failed' => 0,
            'models_created' => 0,
            'models_updated' => 0,
            'models_unchanged' => 0,
            'models_locked' => 0,
            'models_rejected' => 0,
            'models_tiered' => 0,
            'fallback_targets' => [],
            'provider_results' => [],
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
