<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceType;
use App\Enums\WhisparrVersion;
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
            // Loopback with an unused port, never fake()->url(): Faker
            // produces real, often-resolvable domains, so any test path that
            // slipped past Http::fake sent real outbound HTTP from CI (and
            // hung for the full client timeout on slow hosts). Loopback
            // fails fast and deterministically instead.
            'url' => sprintf('http://127.0.0.1:%d', fake()->numberBetween(20000, 64000)),
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

    public function bazarr(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Bazarr,
            'name' => 'Bazarr',
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

    public function prowlarr(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Prowlarr,
            'name' => 'Prowlarr',
        ]);
    }

    public function sabnzbd(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::SABnzbd,
            'name' => 'SABnzbd',
        ]);
    }

    public function whisparr(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Whisparr,
            'name' => 'Whisparr',
        ]);
    }

    public function whisparrVersion(WhisparrVersion $whisparrVersion): static
    {
        return $this->state(fn (array $attributes): array => [
            'settings' => [
                ...(is_array($attributes['settings'] ?? null) ? $attributes['settings'] : []),
                'whisparr_version' => $whisparrVersion->value,
            ],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
