<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\Agents\MediaAgent;
use App\Enums\AiUsageKind;
use App\Models\AiUsageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the AI usage row a benchmark case can be captured from, starting
 * from an invocation id or a stored chat reply.
 */
final readonly class CaptureSource
{
    private const int CHAT_REPLY_WINDOW_SECONDS = 5;

    public function forInvocation(string $invocationId): ?AiUsageRecord
    {
        return AiUsageRecord::query()->where('invocation_id', $invocationId)->first();
    }

    public function forChatMessage(string $messageId): ?AiUsageRecord
    {
        $message = DB::table('agent_conversation_messages')
            ->where('id', $messageId)
            ->where('role', 'assistant')
            ->first();

        if ($message === null) {
            return null;
        }

        $meta = json_decode((string) $message->meta, true);

        if (is_array($meta) && is_string($meta['invocation_id'] ?? null) && $meta['invocation_id'] !== '') {
            return $this->forInvocation($meta['invocation_id']);
        }

        $repliedAt = CarbonImmutable::parse($message->created_at)->startOfSecond();

        $nextReplyAt = DB::table('agent_conversation_messages')
            ->where('conversation_id', $message->conversation_id)
            ->where('role', 'assistant')
            ->where(fn (Builder $query) => $query
                ->where('created_at', '>', $message->created_at)
                ->orWhere(fn (Builder $tie) => $tie->where('created_at', $message->created_at)->where('id', '>', $message->id)))
            ->oldest()
            ->orderBy('id')
            ->value('created_at');

        return AiUsageRecord::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('kind', AiUsageKind::Text)
            ->where('is_benchmark', false)
            ->whereNull('parent_invocation_id')
            ->where('agent_class', MediaAgent::class)
            ->whereBetween('created_at', [$repliedAt, $repliedAt->addSeconds(self::CHAT_REPLY_WINDOW_SECONDS)])
            ->when($nextReplyAt !== null, fn (Builder $query) => $query->where('created_at', '<', CarbonImmutable::parse($nextReplyAt)))
            ->oldest()
            ->orderBy('id')
            ->first();
    }
}
