<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Ai\Agents\MediaAgent;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Models\AiToolInvocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiToolInvocation>
 */
class AiToolInvocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invocation_id' => fake()->uuid(),
            'tool_invocation_id' => fake()->uuid(),
            'tool_class' => SearchMediaTool::class,
            'agent_class' => MediaAgent::class,
            'status' => 'success',
        ];
    }
}
