<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Agents\MediaAgent;
use App\Ai\ChatFailure;
use App\Ai\Routing\ChatToolRouter;
use App\Ai\Routing\ToolGroup;
use App\Ai\Routing\ToolPayload;
use App\Ai\TaskModelResolver;
use App\Enums\AiMode;
use App\Enums\AiTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\SendChatRequest;
use App\Http\Requests\AI\StreamChatRequest;
use App\Http\Streaming\ChatStreamProtocol;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\ClassificationOutcome;
use App\Models\User;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\AiModelRateLimitExceededException;
use App\Services\AiUsage\AiRateLimitGuard;
use App\Services\Chat\ChatAttachmentStore;
use App\Services\Chat\ChatWorkflowContinuation;
use App\Services\Chat\ConversationModelOverride;
use App\Services\Chat\WorkflowContinuationRefused;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('AI/Chat', []);
    }

    public function send(SendChatRequest $sendChatRequest, ChatAttachmentStore $chatAttachmentStore, ChatToolRouter $chatToolRouter, ToolPayload $toolPayload, ChatWorkflowContinuation $chatWorkflowContinuation, ConversationModelOverride $conversationModelOverride): JsonResponse
    {
        $validated = $sendChatRequest->validated();

        $conversationModelOverride->applyToTurn($validated['conversation_id'] ?? null, $validated['override'] ?? []);

        $conversationId = $validated['conversation_id'] ?? null;
        $user = $sendChatRequest->user();

        if (($refusal = $this->refuseTurn($validated, $user)) instanceof JsonResponse) {
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
        $turnKey = ClassificationOutcome::subjectKey('chat_turn', (string) Str::uuid7());

        try {
            // A workflow continuation executes whichever destructive tools the
            // approved steps name, so it always gets the full toolset.
            $groups = $continuation === null ? $chatToolRouter->route($messageToSend, $conversationId, $turnKey) : null;
            $response = $this->agentFor($conversationId, $user, $groups, $chatToolRouter, $toolPayload)
                ->prompt($messageToSend, attachments: $chatAttachmentStore->toSdkAttachments($attachments));
        } catch (Throwable $throwable) {
            return $this->handleAgentFailure($throwable, $user);
        }

        $chatToolRouter->recordToolUse($turnKey, $this->calledToolNames($response));

        $workflowPayload = $chatWorkflowContinuation->claimProposed($user, $turnStartedAt, $response->conversationId ?? null);

        $newConversationId = $response->conversationId ?? null;
        $chatAttachmentStore->assignConversation($attachments, $newConversationId);

        if ($isNewConversation && $newConversationId !== null) {
            $conversationModelOverride->persist($newConversationId);
            $this->seedConversationTitle($newConversationId, $validated['message']);
            dispatch(new GenerateConversationTitle($newConversationId, $validated['message']));
        }

        $resolvedSelection = resolve(TaskModelResolver::class)->resolve(AiTask::Chat);

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $newConversationId,
            'workflow' => $workflowPayload,
            'answered_by' => [
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'reasoning_label' => $resolvedSelection->reasoning->label(),
                'tier' => $resolvedSelection->tier?->toArray(),
            ],
        ]);
    }

    /**
     * Stream a chat turn back to the client as AG-UI events (ChatStreamProtocol).
     *
     * Shares send()'s pre-flight (refuseTurn()). The conversation id
     * travels as the run's `threadId` on RUN_STARTED/RUN_FINISHED, so the client's
     * active conversation is deterministic for brand-new conversations too.
     * Workflow continuations are intentionally NOT supported here — they stay on
     * send().
     */
    public function stream(StreamChatRequest $streamChatRequest, ChatAttachmentStore $chatAttachmentStore, ChatToolRouter $chatToolRouter, ToolPayload $toolPayload, ConversationModelOverride $conversationModelOverride): JsonResponse|StreamableAgentResponse
    {
        $validated = $streamChatRequest->validated();

        $conversationModelOverride->applyToTurn($validated['conversation_id'] ?? null, $validated['override'] ?? []);

        $user = $streamChatRequest->user();

        if (($refusal = $this->refuseTurn($validated, $user)) instanceof JsonResponse) {
            return $refusal;
        }

        $conversationId = $validated['conversation_id'] ?? null;

        $isNewConversation = $conversationId === null;
        $message = $validated['message'];
        $attachments = $chatAttachmentStore->store($user, $streamChatRequest->file('attachments', []));
        $turnKey = ClassificationOutcome::subjectKey('chat_turn', (string) Str::uuid7());

        try {
            $groups = $chatToolRouter->route($message, $conversationId, $turnKey);
            $stream = $this->agentFor($conversationId, $user, $groups, $chatToolRouter, $toolPayload)
                ->stream($message, attachments: $chatAttachmentStore->toSdkAttachments($attachments));
        } catch (Throwable $throwable) {
            return $this->handleAgentFailure($throwable, $user);
        }

        $stream->then(function (TextResponse $response) use ($isNewConversation, $message, $attachments, $chatAttachmentStore, $conversationModelOverride, $chatToolRouter, $turnKey): void {
            $chatToolRouter->recordToolUse($turnKey, $this->calledToolNames($response));

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
    public function pendingWorkflow(Request $request, ChatWorkflowContinuation $chatWorkflowContinuation): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'string', 'uuid'],
        ]);

        // The claim below stamps this id onto the proposal — never stamp a
        // conversation the caller doesn't own (send() applies the same check).
        if (! $this->conversationIsAvailable($validated['conversation_id'], $request->user())) {
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
     * The checks every chat turn passes before the agent runs, in this
     * order: apply the requested mode, the monthly hard cap (402), the chat
     * model's rate limit (429), then conversation ownership (404). Returns
     * the refusal, or null when the turn may proceed. Shared by send() and
     * stream(), which apply the conversation's model override first so the
     * rate limit checks the model the turn will actually run on.
     *
     * @param  array<string, mixed>  $validated
     */
    private function refuseTurn(array $validated, ?User $user): ?JsonResponse
    {
        $this->applyRequestedMode($validated['mode'] ?? null);

        $refusal = $this->enforceBudget() ?? $this->enforceRateLimit();

        if ($refusal instanceof JsonResponse) {
            return $refusal;
        }

        $conversationId = $validated['conversation_id'] ?? null;

        if ($conversationId !== null && ! $this->conversationIsAvailable($conversationId, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        return null;
    }

    /**
     * The chat agent for one turn: the user's conversation (or a new one)
     * with the turn's routed tool groups. Null groups keep every declared tool.
     *
     * @param  list<ToolGroup>|null  $groups
     */
    private function agentFor(?string $conversationId, User $user, ?array $groups, ChatToolRouter $chatToolRouter, ToolPayload $toolPayload): MediaAgent
    {
        return (new MediaAgent)->continueOrStart($conversationId, as: $user)
            ->withTools(fn (array $declared): array => $toolPayload->build($groups === null ? $declared : $chatToolRouter->filter($declared, $groups)));
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
     * Apply the requested advisory/executive mode to the shared AiSettings, if any.
     */
    private function applyRequestedMode(?string $mode): void
    {
        if ($mode !== null) {
            resolve(AiSettings::class)->withMode(AiMode::from($mode));
        }
    }

    /**
     * Enforce the monthly AI budget. Returns a 402 JsonResponse when the hard
     * cap has been exceeded, or null when the request may proceed.
     */
    private function enforceBudget(): ?JsonResponse
    {
        try {
            resolve(AiBudgetGuard::class)->enforce();
        } catch (AiBudgetExceededException $aiBudgetExceededException) {
            return response()->json([
                'error' => 'budget_exceeded',
                'message' => $aiBudgetExceededException->getMessage(),
            ], 402);
        }

        return null;
    }

    /**
     * Refuse the turn up front when the chat model has exhausted a configured
     * rate limit and there is no failover provider to take it. With a
     * failover configured the turn proceeds: EnforceAiRateLimit vetoes the
     * primary inside the SDK and the failover provider serves the turn
     * (streams in particular cannot 429 once the response has started).
     */
    private function enforceRateLimit(): ?JsonResponse
    {
        $aiSettings = resolve(AiSettings::class);
        $modelSelection = $aiSettings->chatSelection();

        if (count($aiSettings->providerChainFor($modelSelection)) > 1) {
            return null;
        }

        try {
            resolve(AiRateLimitGuard::class)->enforce($modelSelection->provider, $modelSelection->model);
        } catch (AiModelRateLimitExceededException $aiModelRateLimitExceededException) {
            return $this->rateLimitedResponse($aiModelRateLimitExceededException);
        }

        return null;
    }

    private function rateLimitedResponse(RateLimitedException $rateLimitedException): JsonResponse
    {
        return response()->json([
            'error' => 'rate_limited',
            'message' => $rateLimitedException->getMessage(),
        ], 429);
    }

    /**
     * Log an agent invocation failure and build the client-facing 500 response.
     * The full message is only surfaced in local for debugging. A rate limit
     * (ours or the provider's, after every failover was exhausted) is the one
     * expected failure and becomes a 429 the chat UI can explain; a hard
     * cap reached mid-run becomes the same 402 as the pre-flight check.
     */
    private function handleAgentFailure(Throwable $throwable, ?User $user): JsonResponse
    {
        if ($throwable instanceof RateLimitedException) {
            return $this->rateLimitedResponse($throwable);
        }

        // EnforceBudgetEachStep stops a run that crosses the hard cap mid-way;
        // answer it exactly like the pre-flight budget check does.
        if ($throwable instanceof AiBudgetExceededException) {
            return response()->json([
                'error' => ChatFailure::code($throwable),
                'message' => ChatFailure::message($throwable),
            ], 402);
        }

        if ($throwable instanceof ProviderConnectionException) {
            return response()->json([
                'error' => ChatFailure::code($throwable),
                'message' => ChatFailure::message($throwable),
            ], 503);
        }

        // Laravel's HTTP-client RequestException truncates response bodies
        // in getMessage(); pull the full body separately so OpenAI's
        // verbose error JSON is visible for debugging.
        $context = [
            'user_id' => $user?->id,
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
        ];

        if ($throwable instanceof RequestException) {
            $context['response_body'] = $throwable->response->body();
            $context['response_status'] = $throwable->response->status();
        }

        Log::error('AI request failed.', $context);

        $payload = ['error' => 'AI request failed.'];

        if (app()->isLocal()) {
            $payload['message'] = $throwable->getMessage();
        }

        return response()->json($payload, 500);
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

    private function conversationIsAvailable(string $conversationId, ?User $user): bool
    {
        $conversationStore = resolve(ConversationStore::class);

        return $user instanceof User
            && $conversationStore instanceof VerifiesConversationOwnership
            && $conversationStore->conversationBelongsTo($conversationId, $user->getMorphClass(), $user->id)
            && DB::table('agent_conversations')->where('id', $conversationId)->whereNull('archived_at')->exists();
    }
}
