<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Models\AiModelPrice;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\AgentPrompted;

class RecordAgentUsage
{
    public function handle(AgentPrompted $agentPrompted): void
    {
        $response = $agentPrompted->response;
        $usage = $response->usage;
        $meta = $response->meta;

        $snapshot = $this->priceSnapshotFor($meta->provider, $meta->model);

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
            'input_per_mtok' => $snapshot['input_per_mtok'] ?? null,
            'output_per_mtok' => $snapshot['output_per_mtok'] ?? null,
            'cache_read_per_mtok' => $snapshot['cache_read_per_mtok'] ?? null,
            'cache_write_per_mtok' => $snapshot['cache_write_per_mtok'] ?? null,
            'reasoning_per_mtok' => $snapshot['reasoning_per_mtok'] ?? null,
            'price_source' => $snapshot === null ? null : 'live',
            // Conversational agents (chat) carry a participant on the response.
            // Non-conversational agents (e.g. PriceFetcherAgent) don't, so we
            // attribute usage to whoever's authenticated in the current request
            // — the user who triggered the run — for cost / budget reporting.
            'user_id' => $response->conversationUser?->id ?? Auth::id(),
            'conversation_id' => $response->conversationId,
            'status' => 'success',
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function priceSnapshotFor(?string $provider, ?string $model): ?array
    {
        if ($provider === null || $provider === '' || $model === null || $model === '') {
            return null;
        }

        // Strip a trailing dated suffix (e.g. "-2025-09-23") so a snapshot
        // recorded against the base model id still resolves for dated
        // variants. Mirrors the JOIN logic in AiUsageReporting.
        $baseModel = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model);

        $price = AiModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $baseModel)
            ->first();

        if (! $price instanceof AiModelPrice) {
            return null;
        }

        return [
            'input_per_mtok' => $price->input_per_mtok,
            'output_per_mtok' => $price->output_per_mtok,
            'cache_read_per_mtok' => $price->cache_read_per_mtok,
            'cache_write_per_mtok' => $price->cache_write_per_mtok,
            'reasoning_per_mtok' => $price->reasoning_per_mtok,
        ];
    }
}
