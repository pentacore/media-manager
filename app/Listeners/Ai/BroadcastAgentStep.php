<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\Agents\MediaAgent;
use App\Events\Ai\AgentStepUpdate;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Events\ToolInvoked;

class BroadcastAgentStep
{
    public function handle(ToolInvoked $toolInvoked): void
    {
        $context = $this->resolveContext($toolInvoked);

        if ($context === null) {
            return;
        }

        event(new AgentStepUpdate($context['user_id'], $context['conversation_id'], $this->shortToolName($toolInvoked->tool::class), AgentStepUpdate::STATUS_FINISHED));
    }

    /**
     * @return array{user_id: int, conversation_id: string}|null
     */
    private function resolveContext(ToolInvoked $toolInvoked): ?array
    {
        $agent = $toolInvoked->agent;

        // Only Conversational agents that use RemembersConversations expose
        // the active conversation id + participant. Background agents (e.g.
        // PriceFetcherAgent) do not — silently skip the broadcast for them.
        if (! in_array(RemembersConversations::class, class_uses_recursive($agent), true)) {
            return null;
        }

        /** @var MediaAgent $agent */
        $conversationId = $agent->currentConversation();
        $participant = $agent->conversationParticipant();

        if ($conversationId === null || $participant === null) {
            return null;
        }

        $userId = is_object($participant) && property_exists($participant, 'id')
            ? (int) $participant->id
            : null;

        // Defensive: if the agent didn't carry the user, fall back to the
        // owner stored alongside the conversation row. Keeps the listener
        // robust against future agent flows that drop the participant.
        if ($userId === null) {
            $userId = (int) DB::table('agent_conversations')
                ->where('id', $conversationId)
                ->value('user_id');

            if ($userId === 0) {
                return null;
            }
        }

        return ['user_id' => $userId, 'conversation_id' => $conversationId];
    }

    private function shortToolName(string $toolClass): string
    {
        $segments = explode('\\', $toolClass);

        return end($segments) ?: $toolClass;
    }
}
