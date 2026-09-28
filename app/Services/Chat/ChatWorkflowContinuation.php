<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The chat side of a ProposeWorkflowTool proposal: claiming the proposal a
 * turn just produced for its conversation, and turning the user's approve or
 * decline into the continuation prompt MediaAgent runs next.
 */
class ChatWorkflowContinuation
{
    /**
     * Transition the user's proposed workflow and return the continuation
     * prompt. Two overlapping requests (double-click, second tab) can both
     * pass the ownership read; only the one whose conditional update flips
     * the proposal away from Proposed proceeds, so destructive execution is
     * never directed twice.
     *
     * @throws WorkflowContinuationRefused
     */
    public function continueFrom(string $workflowId, string $action, ?User $user): string
    {
        $workflow = AiProposedWorkflow::find($workflowId);

        if ($workflow === null || $workflow->user_id !== $user?->id) {
            throw new WorkflowContinuationRefused('Workflow not found.', 404);
        }

        $newStatus = $action === 'approved'
            ? AiProposedWorkflowStatus::Approved
            : AiProposedWorkflowStatus::Declined;

        $won = AiProposedWorkflow::query()
            ->whereKey($workflow->id)
            ->where('status', AiProposedWorkflowStatus::Proposed->value)
            ->update(['status' => $newStatus]);

        if ($won !== 1) {
            throw new WorkflowContinuationRefused('Workflow is no longer pending.', 422);
        }

        $workflow->refresh();

        return $this->continuationPrompt($workflow, $newStatus);
    }

    /**
     * The workflow ProposeWorkflowTool proposed during the just-completed
     * turn. The turn-start timestamp (rather than just an unstamped
     * conversation_id) avoids picking up a sibling tab's pending proposal in
     * the same user's session. A proposal already stamped with this
     * conversation wins; only unstamped ones are claimed, so a sibling tab's
     * pendingWorkflow poll can never steal (re-stamp) a proposal attached to
     * another conversation.
     *
     * @return array{id: string, rationale: string, steps: array<int, array<string, mixed>>}|null
     */
    public function claimProposed(?User $user, CarbonImmutable $since, ?string $conversationId): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $proposedWorkflow = AiProposedWorkflow::where('user_id', $user->id)
            ->where('created_at', '>=', $since)
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

    private function continuationPrompt(AiProposedWorkflow $aiProposedWorkflow, AiProposedWorkflowStatus $aiProposedWorkflowStatus): string
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
}
