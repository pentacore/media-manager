<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Agents\MediaAgent;
use App\Enums\AiMode;
use App\Enums\AiProposedWorkflowStatus;
use App\Http\Controllers\Controller;
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

        if (isset($validated['mode'])) {
            resolve(AiSettings::class)->withMode(AiMode::from($validated['mode']));
        }

        try {
            resolve(AiBudgetGuard::class)->enforce();
        } catch (AiBudgetExceededException $aiBudgetExceededException) {
            return response()->json([
                'error' => 'budget_exceeded',
                'message' => $aiBudgetExceededException->getMessage(),
            ], 402);
        }

        if ($conversationId !== null && ! $this->conversationBelongsToUser($conversationId, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $continuation = $this->resolveWorkflowContinuation($validated, $user);
        if ($continuation instanceof JsonResponse) {
            return $continuation;
        }

        $messageToSend = $continuation ?? $validated['message'];
        $turnStartedAt = CarbonImmutable::now();

        try {
            $agent = $conversationId
                ? (new MediaAgent)->continue($conversationId, as: $user)
                : (new MediaAgent)->forUser($user);
            $response = $agent->prompt($messageToSend);
        } catch (Throwable $throwable) {
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

        $workflowPayload = $this->attachFreshlyProposedWorkflow($user, $turnStartedAt, $response->conversationId ?? null);

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $response->conversationId ?? null,
            'workflow' => $workflowPayload,
        ]);
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

    private function conversationBelongsToUser(string $conversationId, ?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->exists();
    }
}
