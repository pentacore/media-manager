<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Ai\Agents\MediaAgent;
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
}
