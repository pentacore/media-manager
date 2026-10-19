<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Ai\Agents\MediaAgent;
use App\Ai\Routing\ChatToolRouter;
use App\Ai\Routing\ToolGroup;
use App\Ai\Routing\ToolPayload;
use App\Enums\AiMode;
use App\Models\User;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\AiModelRateLimitExceededException;
use App\Services\AiUsage\AiRateLimitGuard;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;

/**
 * The checks every chat turn passes before its agent runs, and that agent.
 * ChatController::send() and stream() share it. Never bind it in a provider:
 * it holds the request's scoped AiSettings, which refuse() changes with the
 * requested mode, so under Octane a longer-lived instance would carry one
 * request's mode into the next. The controller takes it as an action
 * parameter, which builds one per request.
 */
final readonly class ChatTurnGuard
{
    public function __construct(
        private AiSettings $aiSettings,
        private AiBudgetGuard $aiBudgetGuard,
        private AiRateLimitGuard $aiRateLimitGuard,
        private ConversationStore $conversationStore,
        private ChatToolRouter $chatToolRouter,
        private ToolPayload $toolPayload,
        private ChatFailureResponder $chatFailureResponder,
    ) {}

    /**
     * The checks every chat turn passes before the agent runs, in this
     * order: apply the requested mode, the monthly hard cap (402), the chat
     * model's rate limit (429), then conversation ownership (404). Returns
     * the refusal, or null when the turn may proceed. Callers apply the
     * conversation's model override first, so the rate limit checks the
     * model the turn will actually run on.
     *
     * @param  array<string, mixed>  $validated
     */
    public function refuse(array $validated, ?User $user): ?JsonResponse
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
    public function agentFor(?string $conversationId, User $user, ?array $groups): MediaAgent
    {
        return (new MediaAgent)->continueOrStart($conversationId, as: $user)
            ->withTools(fn (array $declared): array => $this->toolPayload->build($groups === null ? $declared : $this->chatToolRouter->filter($declared, $groups)));
    }

    /**
     * Whether the user owns this conversation and it is not archived.
     */
    public function conversationIsAvailable(string $conversationId, ?User $user): bool
    {
        return $user instanceof User
            && $this->conversationStore instanceof VerifiesConversationOwnership
            && $this->conversationStore->conversationBelongsTo($conversationId, $user->getMorphClass(), $user->id)
            && DB::table('agent_conversations')->where('id', $conversationId)->whereNull('archived_at')->exists();
    }

    /**
     * Apply the requested advisory/executive mode to the shared AiSettings, if any.
     */
    private function applyRequestedMode(?string $mode): void
    {
        if ($mode !== null) {
            $this->aiSettings->withMode(AiMode::from($mode));
        }
    }

    /**
     * Enforce the monthly AI budget. Returns a 402 JsonResponse when the hard
     * cap has been exceeded, or null when the request may proceed.
     */
    private function enforceBudget(): ?JsonResponse
    {
        try {
            $this->aiBudgetGuard->enforce();
        } catch (AiBudgetExceededException $aiBudgetExceededException) {
            return $this->chatFailureResponder->budgetExceeded($aiBudgetExceededException);
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
        $modelSelection = $this->aiSettings->chatSelection();

        if (count($this->aiSettings->providerChainFor($modelSelection)) > 1) {
            return null;
        }

        try {
            $this->aiRateLimitGuard->enforce($modelSelection->provider, $modelSelection->model);
        } catch (AiModelRateLimitExceededException $aiModelRateLimitExceededException) {
            return $this->chatFailureResponder->rateLimited($aiModelRateLimitExceededException);
        }

        return null;
    }
}
