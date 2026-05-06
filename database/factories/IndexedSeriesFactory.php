<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IndexedSeries>
 */
class IndexedSeriesFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'service_connection_id' => ServiceConnection::factory()->sonarr(),
            'sonarr_id' => fake()->unique()->numberBetween(1, 100_000),
            'tvdb_id' => fake()->numberBetween(1, 1_000_000),
            'imdb_id' => fake()->numberBetween(1, 9_999_999),
            'title' => $title,
            'sort_title' => mb_strtolower($title),
            'year' => fake()->numberBetween(1990, 2026),
            'title_slug' => Str::slug($title),
            'status' => fake()->randomElement(['continuing', 'ended', 'upcoming']),
            'monitored' => fake()->boolean(),
            'network' => fake()->randomElement(['HBO', 'Netflix', 'AMC', 'BBC']),
            'genres' => fake()->randomElements(['Drama', 'Comedy', 'Sci-Fi', 'Thriller', 'Horror'], 2),
            'overview' => fake()->paragraph(),
            'poster_url' => 'https://example.com/'.fake()->uuid().'.jpg',
            'arr_added_at' => fake()->dateTimeBetween('-2 years'),
        ];
    }
}
