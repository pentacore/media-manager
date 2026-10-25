<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\AnsweredBy;
use App\Ai\Routing\ChatToolRouter;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\SendChatRequest;
use App\Http\Requests\AI\StreamChatRequest;
use App\Http\Streaming\ChatStreamProtocol;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\ClassificationOutcome;
use App\Models\User;
use App\Services\Chat\ChatAttachmentStore;
use App\Services\Chat\ChatCardExtractor;
use App\Services\Chat\ChatFailureResponder;
use App\Services\Chat\ChatTurnGuard;
use App\Services\Chat\ChatWorkflowContinuation;
use App\Services\Chat\ConversationModelOverride;
use App\Services\Chat\WorkflowContinuationRefused;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('AI/Chat', []);
    }

    public function send(SendChatRequest $sendChatRequest, ChatAttachmentStore $chatAttachmentStore, ChatToolRouter $chatToolRouter, ChatTurnGuard $chatTurnGuard, ChatFailureResponder $chatFailureResponder, ChatWorkflowContinuation $chatWorkflowContinuation, ConversationModelOverride $conversationModelOverride, AiSettings $aiSettings, ChatCardExtractor $chatCardExtractor): JsonResponse
    {
        $validated = $sendChatRequest->validated();

        $conversationModelOverride->applyToTurn($validated['conversation_id'] ?? null, $validated['override'] ?? []);

        $conversationId = $validated['conversation_id'] ?? null;
        $user = $sendChatRequest->user();

        if (($refusal = $chatTurnGuard->refuse($validated, $user)) instanceof JsonResponse) {
            return $refusal;
        }

        $continuation = $this->resolveWorkflowContinuation($validated, $user, $chatWorkflowContinuation);

        if ($continuation instanceof JsonResponse) {
            return $continuation;
        }

        $isNewConversation = $conversationId === null;
        $messageToSend = $continuation ?? $validated['message'];
        $turnStartedAt = CarbonImmutable::now();
        $attachments = $chatAttachmentStore->store($user, $sendChatRequest->file('attachments', []));
        // A workflow continuation executes whichever destructive tools the
        // approved steps name, so it always gets the full toolset and is
        // never routed or tracked.
        $turnKey = $continuation === null && $aiSettings->chatRoutingEnabled()
            ? ClassificationOutcome::subjectKey('chat_turn', (string) Str::uuid7())
            : null;

        try {
            $routed = $continuation === null ? $chatToolRouter->route($messageToSend, $conversationId, $turnKey) : null;
            $response = $chatTurnGuard->agentFor($conversationId, $user, $routed)
                ->prompt($messageToSend, attachments: $chatAttachmentStore->toSdkAttachments($attachments));
        } catch (Throwable $throwable) {
            return $chatFailureResponder->respond($throwable, $user);
        }

        if ($turnKey !== null) {
            $chatToolRouter->recordToolUse($turnKey, $this->calledToolNames($response));
        }

        $workflowPayload = $chatWorkflowContinuation->claimProposed($user, $turnStartedAt, $response->conversationId ?? null);

        $newConversationId = $response->conversationId ?? null;
        $chatAttachmentStore->assignConversation($attachments, $newConversationId);

        if ($isNewConversation && $newConversationId !== null) {
            $conversationModelOverride->persist($newConversationId);
            $this->seedConversationTitle($newConversationId, $validated['message']);
            dispatch(new GenerateConversationTitle($newConversationId, $validated['message']));
        }

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $newConversationId,
            'workflow' => $workflowPayload,
            'invocation_id' => $response->invocationId,
            'answered_by' => AnsweredBy::forChat($response->meta->provider, $response->meta->model),
            'cards' => $chatCardExtractor->hydrate($chatCardExtractor->fromToolResults(
                collect($response->steps)
                    ->flatMap(fn (Step $step): array => $step->toolResults)
                    ->map(fn (ToolResult $toolResult): array => $toolResult->toArray())
                    ->all(),
            )),
        ]);
    }

    /**
     * Stream a chat turn back to the client as AG-UI events (ChatStreamProtocol).
     *
     * Shares send()'s pre-flight (ChatTurnGuard::refuse()). The conversation id
     * travels as the run's `threadId` on RUN_STARTED/RUN_FINISHED, so the client's
     * active conversation is deterministic for brand-new conversations too.
     * Workflow continuations are intentionally NOT supported here — they stay on
     * send().
     */
    public function stream(StreamChatRequest $streamChatRequest, ChatAttachmentStore $chatAttachmentStore, ChatToolRouter $chatToolRouter, ChatTurnGuard $chatTurnGuard, ChatFailureResponder $chatFailureResponder, ConversationModelOverride $conversationModelOverride, AiSettings $aiSettings): JsonResponse|StreamableAgentResponse
    {
        $validated = $streamChatRequest->validated();

        $conversationModelOverride->applyToTurn($validated['conversation_id'] ?? null, $validated['override'] ?? []);

        $user = $streamChatRequest->user();

        if (($refusal = $chatTurnGuard->refuse($validated, $user)) instanceof JsonResponse) {
            return $refusal;
        }

        $conversationId = $validated['conversation_id'] ?? null;

        $isNewConversation = $conversationId === null;
        $message = $validated['message'];
        $attachments = $chatAttachmentStore->store($user, $streamChatRequest->file('attachments', []));
        $turnKey = $aiSettings->chatRoutingEnabled()
            ? ClassificationOutcome::subjectKey('chat_turn', (string) Str::uuid7())
            : null;

        try {
            $routed = $chatToolRouter->route($message, $conversationId, $turnKey);
            $stream = $chatTurnGuard->agentFor($conversationId, $user, $routed)
                ->stream($message, attachments: $chatAttachmentStore->toSdkAttachments($attachments));
        } catch (Throwable $throwable) {
            return $chatFailureResponder->respond($throwable, $user);
        }

        $stream->then(function (TextResponse $response) use ($isNewConversation, $message, $attachments, $chatAttachmentStore, $conversationModelOverride, $chatToolRouter, $turnKey): void {
            if ($turnKey !== null) {
                $chatToolRouter->recordToolUse($turnKey, $this->calledToolNames($response));
            }

            $newConversationId = $response->conversationId ?? null;
            $chatAttachmentStore->assignConversation($attachments, $newConversationId);

            if ($isNewConversation && $newConversationId !== null) {
                $conversationModelOverride->persist($newConversationId);
                $this->seedConversationTitle($newConversationId, $message);
                dispatch(new GenerateConversationTitle($newConversationId, $message));
            }
        });

        $stream->catch(function () use ($stream, $isNewConversation, $message, $conversationModelOverride): void {
            // 1.0 stores a failed first turn once a step completed, so the
            // conversation exists — give it the same readable title a
            // successful turn gets. A turn that died before its first step
            // leaves only the pending id behind, with no row to title.
            if ($isNewConversation
                && $stream->conversationId !== null
                && DB::table('agent_conversations')->where('id', $stream->conversationId)->exists()
            ) {
                $conversationModelOverride->persist($stream->conversationId);
                $this->seedConversationTitle($stream->conversationId, $message);
                dispatch(new GenerateConversationTitle($stream->conversationId, $message));
            }
        });

        return $stream->usingProtocol(new ChatStreamProtocol);
    }

    /**
     * Return the workflow proposed during a just-completed streamed turn.
     *
     * The SSE stream can't carry the workflow JSON that send() attaches, so the
     * frontend polls this once after the stream finishes. Reuses the same query
     * as send()'s attach step, claiming the proposal for the given conversation.
     */
    public function pendingWorkflow(Request $request, ChatWorkflowContinuation $chatWorkflowContinuation, ChatTurnGuard $chatTurnGuard): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'string', 'uuid'],
        ]);

        // The claim below stamps this id onto the proposal — never stamp a
        // conversation the caller doesn't own (send() applies the same check).
        if (! $chatTurnGuard->conversationIsAvailable($validated['conversation_id'], $request->user())) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $workflow = $chatWorkflowContinuation->claimProposed(
            $request->user(),
            CarbonImmutable::now()->subMinutes(10),
            $validated['conversation_id'],
        );

        return response()->json(['workflow' => $workflow]);
    }

    /**
     * The names of the tools a finished turn called.
     *
     * @return list<string>
     */
    private function calledToolNames(TextResponse $textResponse): array
    {
        return $textResponse->toolCalls
            ->map(fn (ToolCall $toolCall): string => $toolCall->name)
            ->values()
            ->all();
    }

    /**
     * Write a readable fallback title onto a brand-new conversation row before
     * the queued GenerateConversationTitle job runs. Keeps the picker readable
     * even if the queue worker is offline.
     */
    private function seedConversationTitle(string $conversationId, string $firstUserMessage): void
    {
        $fallback = (string) Str::of($firstUserMessage)->trim()->limit(60);

        if ($fallback === '') {
            return;
        }

        DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->update(['title' => $fallback]);
    }

    /**
     * A workflow continuation's prompt, the refusal to answer with, or null
     * when the request is not a continuation.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveWorkflowContinuation(array $validated, ?User $user, ChatWorkflowContinuation $chatWorkflowContinuation): JsonResponse|string|null
    {
        if (empty($validated['workflow_id']) || empty($validated['workflow_action'])) {
            return null;
        }

        try {
            return $chatWorkflowContinuation->continueFrom((string) $validated['workflow_id'], (string) $validated['workflow_action'], $user);
        } catch (WorkflowContinuationRefused $workflowContinuationRefused) {
            return response()->json(['message' => $workflowContinuationRefused->getMessage()], $workflowContinuationRefused->status);
        }
    }
}
