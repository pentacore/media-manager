<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Ai\Routing\ToolGroup;
use Illuminate\Support\Facades\DB;

/**
 * A conversation's pinned tool groups: always loaded on a routed turn.
 * Stored on the conversation row; a new conversation takes the pins its
 * first turn requested.
 */
final readonly class ConversationToolPins
{
    /**
     * @return list<ToolGroup>
     */
    public function forConversation(string $conversationId): array
    {
        $stored = DB::table('agent_conversations')->where('id', $conversationId)->value('pinned_tool_groups');
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        return is_array($decoded) ? $this->fromValues($decoded) : [];
    }

    /**
     * The pins this turn uses: the stored ones for an existing conversation,
     * the request's for a new one.
     *
     * @param  list<string>  $requested
     * @return list<ToolGroup>
     */
    public function forTurn(?string $conversationId, array $requested): array
    {
        return $conversationId === null ? $this->fromValues($requested) : $this->forConversation($conversationId);
    }

    /**
     * @param  list<ToolGroup>  $groups
     */
    public function persist(string $conversationId, array $groups): void
    {
        DB::table('agent_conversations')->where('id', $conversationId)->update([
            'pinned_tool_groups' => $groups === [] ? null : json_encode(array_map(fn (ToolGroup $toolGroup): string => $toolGroup->value, $groups)),
        ]);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<ToolGroup>
     */
    private function fromValues(array $values): array
    {
        $groups = [];

        foreach ($values as $value) {
            $group = is_string($value) ? ToolGroup::tryFrom($value) : null;

            if ($group instanceof ToolGroup && ! in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
