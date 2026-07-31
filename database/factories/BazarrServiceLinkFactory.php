<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BazarrServiceRole;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BazarrServiceLink>
 */
class BazarrServiceLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bazarr_connection_id' => ServiceConnection::factory()->bazarr(),
            'related_connection_id' => ServiceConnection::factory()->sonarr(),
            'role' => BazarrServiceRole::Sonarr,
        ];
    }

    public function sonarr(): static
    {
        return $this->state(fn (): array => [
            'role' => BazarrServiceRole::Sonarr,
            'related_connection_id' => ServiceConnection::factory()->sonarr(),
        ]);
    }

    public function radarr(): static
    {
        return $this->state(fn (): array => [
            'role' => BazarrServiceRole::Radarr,
            'related_connection_id' => ServiceConnection::factory()->radarr(),
        ]);
    }
}
