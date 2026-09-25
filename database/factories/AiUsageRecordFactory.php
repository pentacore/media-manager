<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Ai\Agents\MediaAgent;
use App\Enums\AiUsageKind;
use App\Models\AiUsageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageRecord>
 */
class AiUsageRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invocation_id' => 'inv-'.fake()->uuid(),
            'agent_class' => MediaAgent::class,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'prompt_tokens' => fake()->numberBetween(100, 1_000_000),
            'completion_tokens' => fake()->numberBetween(0, 500_000),
            'cache_read_input_tokens' => 0,
            'cache_write_input_tokens' => 0,
            'reasoning_tokens' => 0,
            'tool_calls_count' => 0,
            'is_batch' => false,
            'status' => 'success',
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'failed', 'error_message' => 'Provider returned 500.']);
    }

    public function embeddings(): static
    {
        return $this->state(fn (array $attributes): array => ['kind' => AiUsageKind::Embeddings, 'completion_tokens' => 0]);
    }

    public function reranking(): static
    {
        return $this->state(fn (array $attributes): array => ['kind' => AiUsageKind::Reranking, 'completion_tokens' => 0, 'search_units' => 1]);
    }

    public function classification(): static
    {
        return $this->state(fn (array $attributes): array => ['kind' => AiUsageKind::Classification]);
    }
}
