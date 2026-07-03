<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Agents\MediaAgent;
use App\Enums\AiMode;
use App\Enums\AiProposedWorkflowStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
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
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('AI/Chat', []);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'workflow_id' => ['nullable', 'string', 'uuid'],
            'workflow_action' => ['nullable', 'string', 'in:approved,declined'],
            'mode' => ['nullable', 'string', 'in:advisory,executive'],
        ]);

        $conversationId = $validated['conversation_id'] ?? null;
        $user = $request->user();

        $this->applyRequestedMode($validated['mode'] ?? null);

        if (($budgetResponse = $this->enforceBudget()) instanceof JsonResponse) {
            return $budgetResponse;
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

        try {
            $agent = $conversationId
                ? (new MediaAgent)->continue($conversationId, as: $user)
                : (new MediaAgent)->forUser($user);
            $response = $agent->prompt($messageToSend);
        } catch (Throwable $throwable) {
            return $this->handleAgentFailure($throwable, $user);
        }

        $workflowPayload = $this->attachFreshlyProposedWorkflow($user, $turnStartedAt, $response->conversationId ?? null);

        $newConversationId = $response->conversationId ?? null;

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
     * Stream a chat turn back to the client as Server-Sent Events.
     *
     * Mirrors send()'s pre-flight (mode, budget, ownership). We drive the SSE
     * response ourselves (rather than returning the SDK's StreamableAgentResponse
     * directly) so we can append a terminal `conversation_id` event: for a brand-new
     * conversation the id is only minted by the SDK's RememberConversation middleware
     * once the stream is consumed, and no SDK event carries it. Emitting it before
     * `[DONE]` makes the client's active conversation deterministic instead of relying
     * on a recency heuristic. Workflow continuations are intentionally NOT supported
     * here — they stay on send().
     */
    public function stream(Request $request): mixed
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'mode' => ['nullable', 'string', 'in:advisory,executive'],
        ]);

        $user = $request->user();

        $this->applyRequestedMode($validated['mode'] ?? null);

        if (($budgetResponse = $this->enforceBudget()) instanceof JsonResponse) {
            return $budgetResponse;
        }

        $conversationId = $validated['conversation_id'] ?? null;

        if ($conversationId !== null && ! $this->conversationIsAvailable($conversationId, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $isNewConversation = $conversationId === null;
        $message = $validated['message'];

        try {
            $agent = $conversationId
                ? (new MediaAgent)->continue($conversationId, as: $user)
                : (new MediaAgent)->forUser($user);
            $stream = $agent->stream($message);
        } catch (Throwable $throwable) {
            return $this->handleAgentFailure($throwable, $user);
        }

        $stream->then(function ($response) use ($isNewConversation, $message): void {
            $newConversationId = $response->conversationId ?? null;

            if ($isNewConversation && $newConversationId !== null) {
                $this->seedConversationTitle($newConversationId, $message);
                dispatch(new GenerateConversationTitle($newConversationId, $message));
            }
        });

        return response()->stream(function () use ($stream): void {
            // Keep draining the SDK stream even if the browser disconnects:
            // AgentStreamed (and therefore usage recording / budget guard)
            // only fires once iteration completes, so aborting here would
            // consume provider tokens without billing them.
            ignore_user_abort(true);

            foreach ($stream as $event) {
                echo 'data: '.($event)."\n\n";

                // response()->stream() callbacks don't auto-flush per write
                // (unlike the SDK's yield-based toResponse()) — without this
                // the whole SSE body can buffer and arrive as one chunk.
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }

            // The conversation id is populated once the SDK events (and the
            // then() callbacks) have run during iteration above.
            $conversationId = $stream->conversationId ?? null;

            if ($conversationId !== null) {
                echo 'data: '.json_encode([
                    'type' => 'conversation_id',
                    'conversation_id' => $conversationId,
                ])."\n\n";
            }

            echo "data: [DONE]\n\n";
        }, headers: ['Content-Type' => 'text/event-stream']);
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
     * Log an agent invocation failure and build the client-facing 500 response.
     * The full message is only surfaced in local for debugging.
     */
    private function handleAgentFailure(Throwable $throwable, ?User $user): JsonResponse
    {
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

        if ($workflow->status !== AiProposedWorkflowStatus::Proposed) {
            return response()->json(['message' => 'Workflow is no longer pending.'], 422);
        }

        $newStatus = $validated['workflow_action'] === 'approved'
            ? AiProposedWorkflowStatus::Approved
            : AiProposedWorkflowStatus::Declined;

        $workflow->update(['status' => $newStatus]);

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

        $proposedWorkflow = AiProposedWorkflow::where('user_id', $user->id)
            ->where('created_at', '>=', $carbonImmutable)
            ->where('status', AiProposedWorkflowStatus::Proposed)
            ->latest('created_at')
            ->first();

        if ($proposedWorkflow === null) {
            return null;
        }

        $proposedWorkflow->update(['conversation_id' => $conversationId]);

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
        if (! $user instanceof User) {
            return false;
        }

        return DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->exists();
    }
}
