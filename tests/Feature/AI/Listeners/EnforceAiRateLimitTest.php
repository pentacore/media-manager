<?php

declare(strict_types=1);

use App\Ai\Agents\TitleAgent;
use App\Models\AiModelPrice;
use App\Services\AiUsage\AiModelRateLimitExceededException;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiManager;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function (): void {
    resolve(AiSettings::class)->setRateLimitsEnforced(true);

    $price = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini']);
    $price->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1]);

    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'inv-'.uniqid(),
        'agent_class' => 'TestAgent',
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function rateLimitedPrompt(string $model): AgentPrompt
{
    return new AgentPrompt(
        new TitleAgent,
        'Hello',
        [],
        resolve(AiManager::class)->textProvider('openai'),
        $model,
    );
}

test('a prompt for an exhausted model is refused before it reaches the provider', function (): void {
    event(new PromptingAgent('inv-1', rateLimitedPrompt('gpt-5-mini')));
})->throws(AiModelRateLimitExceededException::class);

test('a streamed prompt for an exhausted model is refused as well', function (): void {
    event(new StreamingAgent('inv-2', rateLimitedPrompt('gpt-5-mini')));
})->throws(AiModelRateLimitExceededException::class);

test('a prompt for a model without limits goes through', function (): void {
    event(new PromptingAgent('inv-3', rateLimitedPrompt('gpt-5-nano')));
})->throwsNoExceptions();

test('nothing is refused while enforcement is switched off', function (): void {
    resolve(AiSettings::class)->setRateLimitsEnforced(false);

    event(new PromptingAgent('inv-4', rateLimitedPrompt('gpt-5-mini')));
})->throwsNoExceptions();
