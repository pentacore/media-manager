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
    /**
     * Whether the current run should be priced against the provider's batch
     * tier. The AI SDK has no batch API yet, so nothing flips this on in
     * production — it exists so a future batch pipeline (e.g. embedding
     * backfills routed through a provider batch endpoint) can set it around
     * its dispatch and have usage snapshot the `batch_*` rates. Kept as a
     * simple static toggle rather than speculative dispatch infrastructure.
     */
    public static bool $batchMode = false;

    public function handle(AgentPrompted $agentPrompted): void
    {
        // Streamed runs are registered for both AgentStreamed (explicitly,
        // AIServiceProvider) and — under the fake gateway — AgentPrompted.
        // Dedupe on invocation_id so a double dispatch never double-bills.
        if (AiUsageRecord::where('invocation_id', $agentPrompted->invocationId)->exists()) {
            return;
        }

        $response = $agentPrompted->response;
        $usage = $response->usage;
        $meta = $response->meta;

        $isBatch = self::$batchMode;
        $snapshot = $this->priceSnapshotFor($meta->provider, $meta->model, $isBatch);

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
            // Cap at 64 KB so a runaway tool-stuffed reply can't bloat the
            // row. Detail-modal use only — we don't index or search this.
            'response_text' => $this->truncateResponseText($response->text ?? null),
            'tool_calls_count' => AiToolInvocation::where('invocation_id', $agentPrompted->invocationId)->count(),
            'input_per_mtok' => $snapshot['input_per_mtok'] ?? null,
            'output_per_mtok' => $snapshot['output_per_mtok'] ?? null,
            'cache_read_per_mtok' => $snapshot['cache_read_per_mtok'] ?? null,
            'cache_write_per_mtok' => $snapshot['cache_write_per_mtok'] ?? null,
            'reasoning_per_mtok' => $snapshot['reasoning_per_mtok'] ?? null,
            'is_batch' => $isBatch,
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

    private const int RESPONSE_TEXT_MAX_BYTES = 65_536;

    private function truncateResponseText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (mb_strlen($text, '8bit') <= self::RESPONSE_TEXT_MAX_BYTES) {
            return $text;
        }

        return mb_strcut($text, 0, self::RESPONSE_TEXT_MAX_BYTES - 3, 'UTF-8').'…';
    }

    /**
     * Snapshot the catalog rates onto the usage row. When the run is
     * batch-flagged, each token tier prefers its `batch_*_per_mtok` rate,
     * falling back to the standard rate when the batch column is unset or
     * zero. Snapshotting the batch rates into the standard snapshot keys
     * keeps downstream reporting unchanged.
     *
     * @return array<string, string>|null
     */
    private function priceSnapshotFor(?string $provider, ?string $model, bool $isBatch): ?array
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
            'input_per_mtok' => $this->rateFor($price->input_per_mtok, $price->batch_input_per_mtok, $isBatch),
            'output_per_mtok' => $this->rateFor($price->output_per_mtok, $price->batch_output_per_mtok, $isBatch),
            'cache_read_per_mtok' => $this->rateFor($price->cache_read_per_mtok, $price->batch_cache_read_per_mtok, $isBatch),
            'cache_write_per_mtok' => $this->rateFor($price->cache_write_per_mtok, $price->batch_cache_write_per_mtok, $isBatch),
            'reasoning_per_mtok' => $this->rateFor($price->reasoning_per_mtok, $price->batch_reasoning_per_mtok, $isBatch),
        ];
    }

    /**
     * Pick the batch rate for a token tier when the run is batch-flagged and
     * the batch rate is set and positive; otherwise use the standard rate.
     */
    private function rateFor(string $standard, ?string $batch, bool $isBatch): string
    {
        if ($isBatch && $batch !== null && (float) $batch > 0.0) {
            return $batch;
        }

        return $standard;
    }
}
