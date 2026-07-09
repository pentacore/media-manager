<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebhookHandlingStatus;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_connection_id' => ServiceConnection::factory(),
            'event_type' => fake()->randomElement(['grab', 'download', 'rename', 'test']),
            'payload' => ['eventType' => 'Test', 'data' => fake()->words(3)],
            'processed_at' => null,
            'handling_status' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processed_at' => now(),
        ]);
    }

    public function handled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processed_at' => now(),
            'handling_status' => WebhookHandlingStatus::Handled,
        ]);
    }

    public function ignored(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processed_at' => now(),
            'handling_status' => WebhookHandlingStatus::Ignored,
        ]);
    }
}
