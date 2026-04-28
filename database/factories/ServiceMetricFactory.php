<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use App\Models\ServiceMetric;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<ServiceMetric>
 */
class ServiceMetricFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'service_connection_id' => ServiceConnection::factory(),
            'status' => HealthStatus::Healthy,
            'latency_ms' => $this->faker->numberBetween(20, 200),
            'message' => null,
            'recorded_at' => now(),
        ];
    }

    public function unhealthy(): self
    {
        return $this->state(fn (): array => [
            'status' => HealthStatus::Unhealthy,
            'latency_ms' => null,
            'message' => 'Connection refused',
        ]);
    }
}
