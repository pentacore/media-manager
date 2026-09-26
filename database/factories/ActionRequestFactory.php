<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionRequest>
 */
class ActionRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_event_id' => WebhookEvent::factory(),
            'type' => 'delete_series',
            'source_service' => 'emby',
            'target_service' => 'sonarr',
            'status' => ActionRequestStatus::Pending,
            'requires_approval' => true,
            'approved_by' => null,
            'payload' => ['series_id' => fake()->randomNumber()],
            'result' => null,
        ];
    }

    public function autoExecute(): static
    {
        return $this->state(fn (array $attributes): array => [
            'requires_approval' => false,
            'status' => ActionRequestStatus::Approved,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ActionRequestStatus::Completed,
            'result' => ['success' => true],
        ]);
    }

    public function described(): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => 'Delete series "Severance (2022)"',
            'description' => 'Emby reported "Severance" was removed from the library. Sonarr will delete the series and its files from disk.',
            'details' => [
                ['label' => 'Series', 'value' => 'Severance (2022)'],
                ['label' => 'Delete files', 'value' => 'Yes'],
            ],
            'description_verified' => true,
        ]);
    }
}
