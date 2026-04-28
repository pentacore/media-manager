<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Agents\MediaAgent;
use App\Enums\AiProposedWorkflowStatus;
use App\Http\Controllers\Controller;
use App\Models\AiProposedWorkflow;
use App\Models\User;
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
        ]);

        $conversationId = $validated['conversation_id'] ?? null;
        $user = $request->user();

        if ($conversationId !== null && ! $this->conversationBelongsToUser($conversationId, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        // Workflow continuation: validate ownership/state, transition the workflow,
        // and synthesize the prompt the agent will receive (instead of the raw user text).
        $messageToSend = $validated['message'];
        if (! empty($validated['workflow_id']) && ! empty($validated['workflow_action'])) {
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

            $messageToSend = $this->synthesizeWorkflowContinuation($workflow, $newStatus);
        }

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

        // Detect a freshly-proposed workflow created during this turn (no conversation_id yet).
        $proposedWorkflow = $user !== null
            ? AiProposedWorkflow::where('user_id', $user->id)
                ->whereNull('conversation_id')
                ->where('status', AiProposedWorkflowStatus::Proposed)
                ->latest('created_at')
                ->first()
            : null;

        $workflowPayload = null;
        if ($proposedWorkflow !== null) {
            $proposedWorkflow->update(['conversation_id' => $response->conversationId ?? null]);
            $workflowPayload = [
                'id' => $proposedWorkflow->id,
                'rationale' => $proposedWorkflow->rationale,
                'steps' => $proposedWorkflow->steps,
            ];
        }

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $response->conversationId ?? null,
            'workflow' => $workflowPayload,
        ]);
    }

    private function synthesizeWorkflowContinuation(AiProposedWorkflow $workflow, AiProposedWorkflowStatus $status): string
    {
        $stepsList = collect($workflow->steps)
            ->map(fn (array $step, int $index): string => sprintf(
                '%d. %s on %s — %s',
                $index + 1,
                $step['action'] ?? 'unknown',
                $step['target'] ?? 'unknown',
                $step['reason'] ?? '',
            ))
            ->implode("\n");

        return $status === AiProposedWorkflowStatus::Approved
            ? sprintf(
                "The user has APPROVED workflow %s. Execute these steps now using the appropriate destructive tools (DeleteSeriesTool, DeleteMovieTool, CleanupRequestTool, LibraryScanTool, etc.):\n\n%s",
                $workflow->id,
                $stepsList,
            )
            : sprintf(
                'The user has DECLINED workflow %s. Acknowledge the decline and ask what they would like to do instead.',
                $workflow->id,
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
