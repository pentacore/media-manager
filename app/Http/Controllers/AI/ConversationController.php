<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\RenameConversationRequest;
use App\Http\Requests\AI\UpdateConversationModelRequest;
use App\Models\User;
use App\Services\Chat\ChatAttachmentStore;
use App\Services\Chat\ConversationModelOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
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

    public function show(Request $request, ChatAttachmentStore $chatAttachmentStore, ConversationModelOverride $conversationModelOverride, string $conversation): JsonResponse
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
                'answered_by' => $storedMessage->role === 'assistant' ? [
                    'provider' => $storedMessage->meta['provider'] ?? null,
                    'model' => $storedMessage->meta['model'] ?? null,
                    'reasoning_label' => AiReasoningLevel::tryFrom((string) ($storedMessage->meta['reasoning_level'] ?? ''))?->label(),
                ] : null,
            ])
            ->values()
            ->all();

        return response()->json([
            'id' => $row->id,
            'title' => (string) $row->title,
            'updated_at' => $row->updated_at,
            'override' => $conversationModelOverride->forConversation($conversation),
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

    /**
     * Change the conversation's model/reasoning override; null fields fall
     * back to the admin chat default. Refused while the SDK waits on a tool
     * approval, since resuming must run on the model that paused.
     */
    public function updateModel(
        UpdateConversationModelRequest $updateConversationModelRequest,
        ConversationModelOverride $conversationModelOverride,
        TaskModelResolver $taskModelResolver,
        string $conversation,
    ): JsonResponse {
        if (! $this->conversationBelongsTo($conversation, $updateConversationModelRequest->user())) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $conversationStore = resolve(ConversationStore::class);

        if ($conversationStore instanceof ResolvesPendingApprovals && $conversationStore->pendingApprovalsFor($conversation) !== []) {
            return response()->json([
                'error' => 'pending_approval',
                'message' => __('Answer the pending tool approval before changing the model.'),
            ], 409);
        }

        $validated = $updateConversationModelRequest->validated();
        $hasPair = filled($validated['provider'] ?? null) && filled($validated['model'] ?? null);

        DB::table('agent_conversations')->where('id', $conversation)->update([
            'model_provider' => $hasPair ? $validated['provider'] : null,
            'model' => $hasPair ? $validated['model'] : null,
            'reasoning' => $validated['reasoning'] ?? null,
            'updated_at' => now(),
        ]);

        $conversationModelOverride->applyToTurn($conversation, []);
        $resolvedSelection = $taskModelResolver->resolve(AiTask::Chat);

        return response()->json([
            'override' => $conversationModelOverride->forConversation($conversation),
            'resolved' => [
                'provider' => $resolvedSelection->provider,
                'model' => $resolvedSelection->model,
                'reasoning' => $resolvedSelection->reasoning->value,
                'reasoning_label' => $resolvedSelection->reasoning->label(),
            ],
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
