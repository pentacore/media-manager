<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityLogCategory;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'service_connection_id' => null,
            'category' => ActivityLogCategory::Activity,
            'action' => fake()->randomElement(['created', 'updated', 'deleted']),
            'subject_type' => null,
            'subject_id' => null,
            'description' => fake()->sentence(),
            'metadata' => null,
        ];
    }

    public function audit(): static
    {
        return $this->state(fn (array $attributes): array => ['category' => ActivityLogCategory::Audit]);
    }
}
