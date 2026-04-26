<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use Laravel\Ai\Events\AgentPrompted;

class RecordAgentUsage
{
    public function handle(AgentPrompted $agentPrompted): void
    {
        $response = $agentPrompted->response;
        $usage = $response->usage;
        $meta = $response->meta;

        AiUsageRecord::create([
            'invocation_id' => $agentPrompted->invocationId,
            'agent_class' => $agentPrompted->prompt->agent::class,
            'provider' => $meta->provider,
            'model' => $meta->model,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'tool_calls_count' => AiToolInvocation::where('invocation_id', $agentPrompted->invocationId)->count(),
            'user_id' => $response->conversationUser?->id,
            'conversation_id' => $response->conversationId,
            'status' => 'success',
        ]);
    }
}
