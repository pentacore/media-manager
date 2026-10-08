<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiTaskModel>
 */
class AiTaskModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task' => AiTask::Chat,
            'scope' => AiTaskModel::DEFAULT_SCOPE,
            'provider' => null,
            'model' => null,
            'reasoning' => null,
        ];
    }

    public function task(AiTask $aiTask): static
    {
        return $this->state(fn (array $attributes): array => ['task' => $aiTask]);
    }

    public function event(string $eventKey): static
    {
        return $this->state(fn (array $attributes): array => ['task' => AiTask::Decision, 'scope' => $eventKey]);
    }

    public function selecting(string $provider, string $model): static
    {
        return $this->state(fn (array $attributes): array => ['provider' => $provider, 'model' => $model]);
    }

    public function reasoning(AiReasoningLevel $aiReasoningLevel): static
    {
        return $this->state(fn (array $attributes): array => ['reasoning' => $aiReasoningLevel]);
    }
}
