<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentDecisionStatus;
use App\Models\AgentDecision;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentDecision>
 */
class AgentDecisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_event_id' => WebhookEvent::factory(),
            'service' => 'sonarr',
            'event_type' => 'ManualInteractionRequired',
            'status' => AgentDecisionStatus::NoAction,
            'summary' => fake()->sentence(),
            'actions_count' => 0,
            'action_request_ids' => [],
        ];
    }

    public function completed(int $actions = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AgentDecisionStatus::Completed,
            'actions_count' => $actions,
            'action_request_ids' => range(1, $actions),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AgentDecisionStatus::Failed,
        ]);
    }
}
