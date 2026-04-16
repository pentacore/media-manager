<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceConnection>
 */
class ServiceConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(ServiceType::cases()),
            'name' => fake()->words(2, true),
            'url' => fake()->url(),
            'api_key' => Str::random(32),
            'webhook_token' => Str::random(40),
            'is_active' => true,
            'last_seen_at' => null,
            'version' => null,
            'settings' => null,
        ];
    }

    public function sonarr(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Sonarr,
            'name' => 'Sonarr',
        ]);
    }

    public function radarr(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Radarr,
            'name' => 'Radarr',
        ]);
    }

    public function emby(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Emby,
            'name' => 'Emby',
        ]);
    }

    public function seerr(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Seerr,
            'name' => 'Seerr',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
