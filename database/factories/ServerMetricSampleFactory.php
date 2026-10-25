<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerMetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerMetricSample>
 */
class ServerMetricSampleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'resolution' => ServerMetricSample::MINUTE,
            'recorded_at' => now()->startOfMinute(),
            'cpu_percent' => 12.5,
            'load1' => 0.3,
            'memory_used_bytes' => 2_147_483_648,
            'memory_total_bytes' => 8_589_934_592,
            'swap_used_bytes' => 0,
            'swap_total_bytes' => 2_147_483_648,
        ];
    }

    public function at(CarbonImmutable $recordedAt): static
    {
        return $this->state(fn (array $attributes): array => ['recorded_at' => $recordedAt]);
    }

    public function resolution(int $resolution): static
    {
        return $this->state(fn (array $attributes): array => ['resolution' => $resolution]);
    }
}
