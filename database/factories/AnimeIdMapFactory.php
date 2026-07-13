<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnimeIdMap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnimeIdMap>
 */
class AnimeIdMapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'anilist_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'mal_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'tmdb_tv_id' => fake()->numberBetween(1, 1_000_000),
            'tmdb_movie_id' => null,
            'tvdb_id' => fake()->numberBetween(1, 1_000_000),
            'tmdb_season' => fake()->numberBetween(1, 5),
            'type' => 'TV',
            'user_confirmed' => false,
        ];
    }

    public function tv(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tmdb_tv_id' => fake()->numberBetween(1, 1_000_000),
            'tmdb_movie_id' => null,
            'type' => 'TV',
        ]);
    }

    public function movie(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tmdb_tv_id' => null,
            'tmdb_movie_id' => fake()->numberBetween(1, 1_000_000),
            'tvdb_id' => null,
            'tmdb_season' => null,
            'type' => 'MOVIE',
        ]);
    }

    public function userConfirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_confirmed' => true,
        ]);
    }
}
