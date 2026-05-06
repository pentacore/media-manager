<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IndexedMovie;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IndexedMovie>
 */
class IndexedMovieFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'service_connection_id' => ServiceConnection::factory()->radarr(),
            'radarr_id' => fake()->unique()->numberBetween(1, 100_000),
            'tmdb_id' => fake()->numberBetween(1, 1_000_000),
            'imdb_id' => 'tt'.fake()->numerify('#######'),
            'title' => $title,
            'sort_title' => mb_strtolower($title),
            'original_title' => $title,
            'year' => fake()->numberBetween(1980, 2026),
            'title_slug' => Str::slug($title),
            'status' => fake()->randomElement(['released', 'announced', 'inCinemas', 'tba']),
            'monitored' => fake()->boolean(),
            'has_file' => fake()->boolean(),
            'genres' => fake()->randomElements(['Action', 'Drama', 'Comedy', 'Sci-Fi', 'Thriller'], 2),
            'overview' => fake()->paragraph(),
            'poster_url' => 'https://example.com/'.fake()->uuid().'.jpg',
            'arr_added_at' => fake()->dateTimeBetween('-3 years'),
        ];
    }
}
