<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\Classification\Classifier;
use App\Settings\AiSettings;
use Illuminate\Support\Str;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Storage\StoredMessage;

/**
 * Sends MediaAgent only the tool groups a message needs. Fails open: routing
 * off, a classifier error, or no confident group all mean the full toolset.
 */
final readonly class ChatToolRouter
{
    private const float INCLUDE_AT = 0.5;

    private const float CONFIDENT_AT = 0.35;

    private const int PREVIOUS_REPLY_LIMIT = 1500;

    public function __construct(
        private Classifier $classifier,
        private AiSettings $aiSettings,
    ) {}

    /**
     * The groups to load for this message, or null for the full toolset.
     * Groups whose tools the previous assistant turn called stay loaded, so
     * a "yes, do it" follow-up keeps the tool it confirms.
     *
     * @return list<ToolGroup>|null
     */
    public function route(string $message, ?string $conversationId): ?array
    {
        if (! $this->aiSettings->chatRoutingEnabled()) {
            return null;
        }

        [$previousReply, $previousToolNames] = $this->previousTurn($conversationId);

        $answers = $this->classifier->classify(
            self::class,
            ['message' => $message, 'previous_assistant_reply' => Str::limit($previousReply, self::PREVIOUS_REPLY_LIMIT)],
            collect(ToolGroup::cases())
                ->mapWithKeys(fn (ToolGroup $toolGroup): array => [$toolGroup->value => new Boolean($toolGroup->question())])
                ->all(),
        );

        if ($answers === null) {
            return null;
        }

        $probabilities = collect($answers)->map(fn (Answer $answer): float => $answer instanceof BooleanAnswer ? $answer->probability : 0.0);

        if ($probabilities->max() < self::CONFIDENT_AT) {
            return null;
        }

        return $probabilities->filter(fn (float $probability): bool => $probability >= self::INCLUDE_AT)
            ->keys()
            ->map(fn (string $value): ToolGroup => ToolGroup::from($value))
            ->merge(collect($previousToolNames)->flatMap(fn (string $name): array => ToolGroup::forToolName($name)))
            ->unique(fn (ToolGroup $toolGroup): string => $toolGroup->value)
            ->values()
            ->all();
    }

    /**
     * Keep the core tools plus every tool in the given groups, in declared
     * order. Tools the agent did not declare (missing connections) stay out.
     *
     * @param  array<int, object>  $declared
     * @param  list<ToolGroup>  $groups
     * @return array<int, object>
     */
    public function filter(array $declared, array $groups): array
    {
        $allowed = array_merge(ToolGroup::core(), ...array_map(static fn (ToolGroup $toolGroup): array => $toolGroup->toolClasses(), $groups));

        return array_values(array_filter($declared, static fn (object $tool): bool => in_array($tool::class, $allowed, true)));
    }

    /**
     * The newest assistant reply and the tool names it called.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function previousTurn(?string $conversationId): array
    {
        $conversationStore = resolve(ConversationStore::class);

        if ($conversationId === null || ! $conversationStore instanceof PaginatesConversations) {
            return ['', []];
        }

        $last = collect($conversationStore->paginateConversationMessages($conversationId, 4)->items())
            ->first(fn (StoredMessage $storedMessage): bool => $storedMessage->role === 'assistant');

        if (! $last instanceof StoredMessage) {
            return ['', []];
        }

        return [
            $last->content,
            array_values(array_unique(array_filter(array_map(
                static fn (array $toolCall): string => (string) ($toolCall['name'] ?? ''),
                $last->toolCalls(),
            )))),
        ];
    }
}
