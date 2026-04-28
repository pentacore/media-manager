<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiProposedWorkflow>
 */
class AiProposedWorkflowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'conversation_id' => (string) Str::uuid7(),
            'rationale' => fake()->sentence(8),
            'steps' => [
                ['action' => 'delete_series', 'target' => 'Demo Show', 'reason' => 'Unwatched in 8 months.'],
                ['action' => 'delete_movie', 'target' => 'Demo Movie', 'reason' => 'Below quality threshold.'],
            ],
            'status' => AiProposedWorkflowStatus::Proposed,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => AiProposedWorkflowStatus::Approved]);
    }

    public function declined(): static
    {
        return $this->state(['status' => AiProposedWorkflowStatus::Declined]);
    }
}
