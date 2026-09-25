<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Enums\AiUsageKind;
use App\Models\AiToolInvocation;
use App\Services\AiUsage\RunUsageAccumulator;
use App\Services\AiUsage\UsageColumns;
use App\Services\AiUsage\UsageRecordWriter;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\AgentPrompted;

class RecordAgentUsage
{
    private const int RESPONSE_TEXT_MAX_BYTES = 65_536;

    public function handle(AgentPrompted $agentPrompted): void
    {
        // Streamed runs are registered for both AgentStreamed (explicitly,
        // AIServiceProvider) and — under the fake gateway — AgentPrompted.
        // UsageRecordWriter dedupes on invocation_id (fast-path check plus
        // the DB unique constraint) so a double dispatch never double-bills.
        $response = $agentPrompted->response;
        $meta = $response->meta;

        resolve(UsageRecordWriter::class)->record([
            'invocation_id' => $agentPrompted->invocationId,
            'kind' => AiUsageKind::Text,
            'agent_class' => $agentPrompted->prompt->agent::class,
            'provider' => $meta->provider,
            'model' => $meta->model,
            ...UsageColumns::fromText($response->usage),
            // Cap at 64 KB so a runaway tool-stuffed reply can't bloat the
            // row. Detail-modal use only — we don't index or search this.
            'response_text' => $this->truncateResponseText($response->text ?? null),
            'tool_calls_count' => AiToolInvocation::where('invocation_id', $agentPrompted->invocationId)->count(),
            // Conversational agents (chat) carry a participant on the response.
            // Non-conversational agents (e.g. PriceFetcherAgent) don't, so we
            // attribute usage to the run's AiRunAttribution user (set by
            // queued callers), then whoever is authenticated in the request.
            'user_id' => $response->conversationUser?->id
                ?? resolve(AiRunAttribution::class)->user()?->id
                ?? Auth::id(),
            'conversation_id' => $response->conversationId,
            // Set when this run is a sub-agent invoked as a tool; its usage is
            // not folded into the parent's response, so each row bills once.
            'parent_invocation_id' => $agentPrompted->prompt->parentInvocationId ?? null,
        ]);

        // The run finished; its step totals were only needed had it failed.
        resolve(RunUsageAccumulator::class)->forget($agentPrompted->invocationId);
    }

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
}
