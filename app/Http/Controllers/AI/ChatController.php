<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Agents\MediaAgent;
use App\Ai\ChatFailure;
use App\Ai\Routing\ChatToolRouter;
use App\Enums\AiMode;
use App\Enums\AiProposedWorkflowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\SendChatRequest;
use App\Http\Requests\AI\StreamChatRequest;
use App\Http\Streaming\ChatStreamProtocol;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\AiModelRateLimitExceededException;
use App\Services\AiUsage\AiRateLimitGuard;
use App\Services\Chat\ChatAttachmentStore;
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
use Laravel\Ai\Responses\StreamableAgentResponse;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('AI/Chat', []);
    }

    public function send(SendChatRequest $sendChatRequest, ChatAttachmentStore $chatAttachmentStore, ChatToolRouter $chatToolRouter): JsonResponse
    {
        $validated = $sendChatRequest->validated();

        $conversationId = $validated['conversation_id'] ?? null;
        $user = $sendChatRequest->user();

        $this->applyRequestedMode($validated['mode'] ?? null);

        if (($budgetResponse = $this->enforceBudget()) instanceof JsonResponse) {
            return $budgetResponse;
        }

        if (($rateLimitResponse = $this->enforceRateLimit()) instanceof JsonResponse) {
            return $rateLimitResponse;
        }

        if ($conversationId !== null && ! $this->conversationIsAvailable($conversationId, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $continuation = $this->resolveWorkflowContinuation($validated, $user);
        if ($continuation instanceof JsonResponse) {
            return $continuation;
        }

        $isNewConversation = $conversationId === null;
        $messageToSend = $continuation ?? $validated['message'];
        $turnStartedAt = CarbonImmutable::now();
        $attachments = $chatAttachmentStore->store($user, $sendChatRequest->file('attachments', []));

        try {
            // A workflow continuation executes whichever destructive tools the
            // approved steps name, so it always gets the full toolset.
            $groups = $continuation === null ? $chatToolRouter->route($messageToSend, $conversationId) : null;
            $agent = (new MediaAgent)->continueOrStart($conversationId, as: $user)
                ->withTools(fn (array $declared): array => $groups === null ? $declared : $chatToolRouter->filter($declared, $groups));
            $aiSettings = resolve(AiSettings::class);
            $chain = $aiSettings->providerChainWithModel($aiSettings->model());
            $sdkAttachments = $chatAttachmentStore->toSdkAttachments($attachments);
            $response = $chain === null
                ? $agent->prompt($messageToSend, attachments: $sdkAttachments)
                : $agent->prompt($messageToSend, attachments: $sdkAttachments, provider: $chain);
        } catch (Throwable $throwable) {
            return $this->handleAgentFailure($throwable, $user);
        }

        $workflowPayload = $this->attachFreshlyProposedWorkflow($user, $turnStartedAt, $response->conversationId ?? null);

        $newConversationId = $response->conversationId ?? null;
        $chatAttachmentStore->assignConversation($attachments, $newConversationId);

        if ($isNewConversation && $newConversationId !== null) {
            $this->seedConversationTitle($newConversationId, $validated['message']);
            dispatch(new GenerateConversationTitle($newConversationId, $validated['message']));
        }

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $newConversationId,
            'workflow' => $workflowPayload,
        ]);
    }

    /**
     * Stream a chat turn back to the client as AG-UI events (ChatStreamProtocol).
     *
     * Mirrors send()'s pre-flight (mode, budget, ownership). The conversation id
     * travels as the run's `threadId` on RUN_STARTED/RUN_FINISHED, so the client's
     * active conversation is deterministic for brand-new conversations too.
     * Workflow continuations are intentionally NOT supported here — they stay on
     * send().
     */
    public function stream(StreamChatRequest $streamChatRequest, ChatAttachmentStore $chatAttachmentStore, ChatToolRouter $chatToolRouter): JsonResponse|StreamableAgentResponse
    {
        $validated = $streamChatRequest->validated();

        $user = $streamChatRequest->user();

        $this->applyRequestedMode($validated['mode'] ?? null);

        if (($budgetResponse = $this->enforceBudget()) instanceof JsonResponse) {
            return $budgetResponse;
        }

        if (($rateLimitResponse = $this->enforceRateLimit()) instanceof JsonResponse) {
            return $rateLimitResponse;
        }

        $conversationId = $validated['conversation_id'] ?? null;

        if ($conversationId !== null && ! $this->conversationIsAvailable($conversationId, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $isNewConversation = $conversationId === null;
        $message = $validated['message'];
        $attachments = $chatAttachmentStore->store($user, $streamChatRequest->file('attachments', []));

        try {
            $groups = $chatToolRouter->route($message, $conversationId);
            $agent = (new MediaAgent)->continueOrStart($conversationId, as: $user)
                ->withTools(fn (array $declared): array => $groups === null ? $declared : $chatToolRouter->filter($declared, $groups));
            $aiSettings = resolve(AiSettings::class);
            $chain = $aiSettings->providerChainWithModel($aiSettings->model());
            $sdkAttachments = $chatAttachmentStore->toSdkAttachments($attachments);
            $stream = $chain === null
                ? $agent->stream($message, attachments: $sdkAttachments)
                : $agent->stream($message, attachments: $sdkAttachments, provider: $chain);
        } catch (Throwable $throwable) {
            return $this->handleAgentFailure($throwable, $user);
        }

        $stream->then(function ($response) use ($isNewConversation, $message, $attachments, $chatAttachmentStore): void {
            $newConversationId = $response->conversationId ?? null;
            $chatAttachmentStore->assignConversation($attachments, $newConversationId);

            if ($isNewConversation && $newConversationId !== null) {
                $this->seedConversationTitle($newConversationId, $message);
                dispatch(new GenerateConversationTitle($newConversationId, $message));
            }
        });

        $stream->catch(function () use ($stream, $isNewConversation, $message): void {
            // 1.0 stores a failed first turn once a step completed, so the
            // conversation exists — give it the same readable title a
            // successful turn gets. A turn that died before its first step
            // leaves only the pending id behind, with no row to title.
            if ($isNewConversation
                && $stream->conversationId !== null
                && DB::table('agent_conversations')->where('id', $stream->conversationId)->exists()
            ) {
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
    public function pendingWorkflow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'string', 'uuid'],
        ]);

        // The claim below stamps this id onto the proposal — never stamp a
        // conversation the caller doesn't own (send() applies the same check).
        if (! $this->conversationIsAvailable($validated['conversation_id'], $request->user())) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $workflow = $this->attachFreshlyProposedWorkflow(
            $request->user(),
            CarbonImmutable::now()->subMinutes(10),
            $validated['conversation_id'],
        );

        return response()->json(['workflow' => $workflow]);
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

        if ($aiSettings->providerChain() !== null) {
            return null;
        }

        try {
            resolve(AiRateLimitGuard::class)->enforce($aiSettings->primaryProvider()->value, $aiSettings->model());
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
     * expected failure and becomes a 429 the chat UI can explain.
     */
    private function handleAgentFailure(Throwable $throwable, ?User $user): JsonResponse
    {
        if ($throwable instanceof RateLimitedException) {
            return $this->rateLimitedResponse($throwable);
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
     * Validate + transition a workflow continuation. Returns:
     * - `JsonResponse` when validation fails (caller short-circuits with it)
     * - `string` synthesized prompt when continuation succeeds
     * - `null` when the request is not a continuation (caller uses raw message)
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveWorkflowContinuation(array $validated, ?User $user): JsonResponse|string|null
    {
        if (empty($validated['workflow_id']) || empty($validated['workflow_action'])) {
            return null;
        }

        $workflow = AiProposedWorkflow::find($validated['workflow_id']);

        if ($workflow === null || $workflow->user_id !== $user?->id) {
            return response()->json(['message' => 'Workflow not found.'], 404);
        }

        $newStatus = $validated['workflow_action'] === 'approved'
            ? AiProposedWorkflowStatus::Approved
            : AiProposedWorkflowStatus::Declined;

        // Conditional transition: two overlapping requests (double-click,
        // second tab) both passed the status read above and the winner's
        // approval directed destructive execution twice. Only the request
        // whose update flips Proposed away proceeds.
        $won = AiProposedWorkflow::query()
            ->whereKey($workflow->id)
            ->where('status', AiProposedWorkflowStatus::Proposed->value)
            ->update(['status' => $newStatus]);

        if ($won !== 1) {
            return response()->json(['message' => 'Workflow is no longer pending.'], 422);
        }

        $workflow->refresh();

        return $this->synthesizeWorkflowContinuation($workflow, $newStatus);
    }

    /**
     * Detect a workflow proposed by ProposeWorkflowTool during the just-completed
     * turn. We use the turn-start timestamp (rather than just `whereNull('conversation_id')`)
     * to avoid picking up a sibling tab's pending proposal in the same admin's session.
     *
     * @return array{id: string, rationale: string, steps: array<int, array<string, mixed>>}|null
     */
    private function attachFreshlyProposedWorkflow(?User $user, CarbonImmutable $carbonImmutable, ?string $conversationId): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        // Prefer a proposal already stamped with this conversation; only
        // claim unstamped ones. Without the scoping, a sibling tab's
        // pendingWorkflow poll could steal (re-stamp) a proposal that was
        // already attached to another conversation.
        $proposedWorkflow = AiProposedWorkflow::where('user_id', $user->id)
            ->where('created_at', '>=', $carbonImmutable)
            ->where('status', AiProposedWorkflowStatus::Proposed)
            ->where(function ($query) use ($conversationId): void {
                $query->whereNull('conversation_id')
                    ->orWhere('conversation_id', $conversationId);
            })
            ->latest('created_at')
            ->first();

        if ($proposedWorkflow === null) {
            return null;
        }

        if ($proposedWorkflow->conversation_id === null) {
            $proposedWorkflow->update(['conversation_id' => $conversationId]);
        }

        return [
            'id' => $proposedWorkflow->id,
            'rationale' => $proposedWorkflow->rationale,
            'steps' => $proposedWorkflow->steps,
        ];
    }

    private function synthesizeWorkflowContinuation(AiProposedWorkflow $aiProposedWorkflow, AiProposedWorkflowStatus $aiProposedWorkflowStatus): string
    {
        $stepsList = collect($aiProposedWorkflow->steps)
            ->map(fn (array $step, int $index): string => sprintf(
                '%d. %s on %s — %s',
                $index + 1,
                $step['action'] ?? 'unknown',
                $step['target'] ?? 'unknown',
                $step['reason'] ?? '',
            ))
            ->implode("\n");

        return $aiProposedWorkflowStatus === AiProposedWorkflowStatus::Approved
            ? sprintf(
                "The user has APPROVED workflow %s. Execute each step now using the destructive tool that matches its action — do NOT call ProposeWorkflowTool again for these steps.\n\n%s",
                $aiProposedWorkflow->id,
                $stepsList,
            )
            : sprintf(
                'The user has DECLINED workflow %s. Acknowledge the decline and ask what they would like to do instead.',
                $aiProposedWorkflow->id,
            );
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
