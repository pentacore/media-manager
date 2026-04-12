<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActionTypeConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionTypeConfig>
 */
class ActionTypeConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->unique()->slug(2),
            'label' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'requires_approval' => true,
            'is_enabled' => true,
        ];
    }
}
