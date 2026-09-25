<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Http\Requests\AI\RenameConversationRequest;
use App\Models\User;
use App\Services\Chat\ChatAttachmentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Storage\StoredMessage;

class ConversationController extends Controller
{
    private const int RECENT_LIMIT = 20;

    private const int HISTORY_PAGE_SIZE = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('agent_conversations')
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->id)
            ->whereNull('archived_at')
            ->latest('updated_at')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'title', 'updated_at']);

        return response()->json([
            'data' => $rows->map(fn ($row): array => [
                'id' => $row->id,
                'title' => (string) $row->title,
                'updated_at' => $row->updated_at,
            ])->all(),
        ]);
    }

    public function show(Request $request, ChatAttachmentStore $chatAttachmentStore, string $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->conversationBelongsTo($conversation, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $row = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->first();

        if ($row === null || $row->archived_at !== null) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $conversationStore = resolve(ConversationStore::class);
        abort_unless($conversationStore instanceof PaginatesConversations, 500);

        $cursorPaginator = $conversationStore->paginateConversationMessages(
            $conversation,
            self::HISTORY_PAGE_SIZE,
            cursor: $request->string('cursor')->value() ?: null,
        );

        $messages = collect($cursorPaginator->items())
            ->reverse()
            ->filter(fn (StoredMessage $storedMessage): bool => in_array($storedMessage->role, ['user', 'assistant'], true)
                && (trim($storedMessage->content) !== '' || $storedMessage->status === MessageStatus::Failed || $storedMessage->attachments !== []))
            ->map(fn (StoredMessage $storedMessage): array => [
                'role' => $storedMessage->role,
                'text' => $storedMessage->content,
                'ts' => ($storedMessage->createdAt?->getTimestamp() ?? 0) * 1000,
                'reasoning' => collect($storedMessage->steps)->pluck('reasoning')->filter()->implode("\n\n"),
                'attachments' => $chatAttachmentStore->forMessage($storedMessage->attachments),
                'failed' => $storedMessage->status === MessageStatus::Failed,
            ])
            ->values()
            ->all();

        return response()->json([
            'id' => $row->id,
            'title' => (string) $row->title,
            'updated_at' => $row->updated_at,
            'messages' => $messages,
            'next_cursor' => $cursorPaginator->nextCursor()?->encode(),
        ]);
    }

    public function rename(RenameConversationRequest $renameConversationRequest, string $conversation): JsonResponse
    {
        $user = $renameConversationRequest->user();

        $row = $this->conversationBelongsTo($conversation, $user)
            ? DB::table('agent_conversations')->where('id', $conversation)->first(['id'])
            : null;

        if ($row === null) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $title = $renameConversationRequest->validated()['title'];

        DB::table('agent_conversations')
            ->where('id', $conversation)
            ->update([
                'title' => $title,
                'updated_at' => now(),
            ]);

        return response()->json([
            'id' => $row->id,
            'title' => $title,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    private function conversationBelongsTo(string $conversationId, ?User $user): bool
    {
        $conversationStore = resolve(ConversationStore::class);

        return $user instanceof User
            && $conversationStore instanceof VerifiesConversationOwnership
            && $conversationStore->conversationBelongsTo($conversationId, $user->getMorphClass(), $user->id);
    }
}
