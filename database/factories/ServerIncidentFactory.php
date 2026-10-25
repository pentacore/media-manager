<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerIncident>
 */
class ServerIncidentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'key' => sprintf('disk:/mnt/%s', fake()->unique()->slug(2)),
            'severity' => IncidentSeverity::Warning,
            'status' => IncidentStatus::Open,
            'title' => fake()->sentence(4),
            'detail' => fake()->sentence(),
            'context' => [],
            'opened_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'clear_streak' => 0,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => IncidentStatus::Open,
            'resolved_at' => null,
            'resolve_notified_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => IncidentStatus::Resolved,
            'resolved_at' => now(),
            'resolve_notified_at' => now(),
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn (array $attributes): array => ['severity' => IncidentSeverity::Warning]);
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes): array => ['severity' => IncidentSeverity::Critical]);
    }

    public function key(string $key): static
    {
        return $this->state(fn (array $attributes): array => ['key' => $key]);
    }
}
