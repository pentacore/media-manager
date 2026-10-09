<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\Classification\ClassificationOutcomeRecorder;
use App\Ai\Classification\Classifier;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Storage\StoredMessage;
use Throwable;

/**
 * Sends MediaAgent only the tool groups a message needs. Fails open: routing
 * off, a classifier error, or no group meeting the inclusion threshold all
 * mean the full toolset.
 */
final readonly class ChatToolRouter
{
    public const float INCLUDE_AT = 0.5;

    private const int PREVIOUS_REPLY_LIMIT = 1500;

    public function __construct(
        private Classifier $classifier,
        private AiSettings $aiSettings,
        private ClassificationOutcomeRecorder $classificationOutcomeRecorder,
    ) {}

    /**
     * The groups to load for this message, or null for the full toolset.
     * Groups whose tools the previous assistant turn called stay loaded, so
     * a "yes, do it" follow-up keeps the tool it confirms. When $turnKey is
     * given and classification returned answers, records one outcome row
     * per group.
     *
     * @return list<ToolGroup>|null
     */
    public function route(string $message, ?string $conversationId, ?string $turnKey = null): ?array
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
            Classifier::CHAT_TIMEOUT_SECONDS,
        );

        if ($answers === null) {
            return null;
        }

        $probabilities = collect($answers)->map(fn (Answer $answer): float => $answer instanceof BooleanAnswer ? $answer->probability : 0.0);

        $groups = $probabilities->max() < self::INCLUDE_AT
            ? null
            : $probabilities->filter(fn (float $probability): bool => $probability >= self::INCLUDE_AT)
                ->keys()
                ->map(fn (string $value): ToolGroup => ToolGroup::from($value))
                ->merge(collect($previousToolNames)->flatMap(fn (string $name): array => ToolGroup::forToolName($name)))
                ->unique(fn (ToolGroup $toolGroup): string => $toolGroup->value)
                ->values()
                ->all();

        if ($turnKey !== null) {
            $this->recordRouting($turnKey, $probabilities->all(), $groups);
        }

        return $groups;
    }

    /**
     * One row per group: included when the turn loaded it (every group is
     * loaded when routing fell back to the full toolset).
     *
     * @param  array<string, float>  $probabilities
     * @param  list<ToolGroup>|null  $groups
     */
    private function recordRouting(string $turnKey, array $probabilities, ?array $groups): void
    {
        $included = $groups === null
            ? array_keys($probabilities)
            : array_map(static fn (ToolGroup $toolGroup): string => $toolGroup->value, $groups);

        foreach ($probabilities as $group => $probability) {
            $this->classificationOutcomeRecorder->record(
                ClassificationGate::ChatRouting,
                $turnKey,
                $group,
                $probability,
                in_array($group, $included, true) ? ClassificationVerdict::Included : ClassificationVerdict::Excluded,
                self::INCLUDE_AT,
            );
        }
    }

    /**
     * Resolve the turn's included groups: positive when the reply called one
     * of the group's tools. Never throws: ToolGroup::forToolName() resolves
     * sub-agent classes from the container, and a lookup failure here must
     * not fail a turn that already succeeded.
     *
     * @param  list<string>  $toolNames
     */
    public function recordToolUse(string $turnKey, array $toolNames): void
    {
        try {
            $used = collect($toolNames)
                ->flatMap(fn (string $name): array => ToolGroup::forToolName($name))
                ->map(fn (ToolGroup $toolGroup): string => $toolGroup->value)
                ->unique()
                ->all();

            foreach (ToolGroup::cases() as $toolGroup) {
                $isUsed = in_array($toolGroup->value, $used, true);

                $this->classificationOutcomeRecorder->resolve(
                    ClassificationGate::ChatRouting,
                    $turnKey,
                    $isUsed,
                    $isUsed ? 'used' : 'not used',
                    [ClassificationVerdict::Included],
                    $toolGroup->value,
                );
            }
        } catch (Throwable $throwable) {
            Log::warning('Chat routing outcome tracking failed.', [
                'operation' => 'recordToolUse',
                'turn_key' => $turnKey,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
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
