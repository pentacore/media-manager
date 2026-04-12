<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbyActivity>
 */
class EmbyActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emby_user_link_id' => EmbyUserLink::factory(),
            'media_type' => fake()->randomElement(['movie', 'episode']),
            'media_title' => fake()->words(3, true),
            'series_title' => fake()->optional()->words(2, true),
            'emby_item_id' => fake()->uuid(),
            'action' => fake()->randomElement(['played', 'stopped', 'finished']),
            'duration_ticks' => fake()->optional()->numberBetween(10000000, 90000000000),
            'play_position' => fake()->optional()->numberBetween(0, 90000000000),
        ];
    }
}
