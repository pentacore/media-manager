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

    /**
     * The stored name of RequestToolsTool, the tool the assistant asks for more groups with.
     */
    public const string TOOL_REQUEST_NAME = 'RequestToolsTool';

    private const int PREVIOUS_REPLY_LIMIT = 1500;

    public function __construct(
        private Classifier $classifier,
        private AiSettings $aiSettings,
        private ClassificationOutcomeRecorder $classificationOutcomeRecorder,
    ) {}

    /**
     * The groups to load for this message and why, or null for the full
     * toolset. Sources merge in order picked, carried over, approved, pinned,
     * so a later source wins per group. Groups whose tools the previous
     * assistant turn called stay loaded (a "yes, do it" follow-up keeps the
     * tool it confirms). Requested loads count only when the latest reply
     * made that tool request. Pins change nothing when routing falls back to
     * the full toolset. When $turnKey is given and classification returned
     * answers, records one outcome row per group.
     *
     * @param  list<ToolGroup>  $pinned
     * @param  list<ToolGroup>  $requestedLoads
     */
    public function route(string $message, ?string $conversationId, ?string $turnKey = null, array $pinned = [], array $requestedLoads = []): ?RoutedToolGroups
    {
        if (! $this->aiSettings->chatRoutingEnabled()) {
            return null;
        }

        $previous = $this->previousTurn($conversationId);

        $answers = $this->classifier->classify(
            self::class,
            ['message' => $message, 'previous_assistant_reply' => Str::limit($previous['reply'], self::PREVIOUS_REPLY_LIMIT)],
            collect(ToolGroup::cases())
                ->mapWithKeys(fn (ToolGroup $toolGroup): array => [$toolGroup->value => new Boolean($toolGroup->question())])
                ->all(),
            Classifier::CHAT_TIMEOUT_SECONDS,
        );

        if ($answers === null) {
            return null;
        }

        $probabilities = collect($answers)->map(fn (Answer $answer): float => $answer instanceof BooleanAnswer ? $answer->probability : 0.0);

        $routed = null;

        if ($probabilities->max() >= self::INCLUDE_AT) {
            $sources = [];

            foreach ($probabilities as $group => $probability) {
                if ($probability >= self::INCLUDE_AT) {
                    $sources[$group] = ToolGroupSource::Picked;
                }
            }

            $sources = array_merge($sources, $this->carriedOverFrom($previous));

            foreach ($requestedLoads as $toolGroup) {
                if (in_array($toolGroup->value, $previous['requested'], true)) {
                    $sources[$toolGroup->value] = ToolGroupSource::Assistant;
                }
            }

            foreach ($pinned as $toolGroup) {
                $sources[$toolGroup->value] = ToolGroupSource::Pinned;
            }

            $routed = new RoutedToolGroups(RoutedToolGroups::ordered($sources));
        }

        if ($turnKey !== null) {
            $this->recordRouting($turnKey, $probabilities->all(), $routed?->groups());
        }

        return $routed;
    }

    /**
     * The groups the next message carries from the latest reply: those whose
     * tools it called, as `assistant` when that reply loaded them as
     * `assistant` and `carried_over` otherwise.
     *
     * @return array<string, ToolGroupSource>
     */
    public function carriedOver(?string $conversationId): array
    {
        return $this->carriedOverFrom($this->previousTurn($conversationId));
    }

    /**
     * @param  array{reply: string, tools: list<string>, groups: ?RoutedToolGroups, requested: list<string>}  $previous
     * @return array<string, ToolGroupSource>
     */
    private function carriedOverFrom(array $previous): array
    {
        $sources = [];

        foreach ($previous['tools'] as $name) {
            foreach (ToolGroup::forToolName($name) as $toolGroup) {
                $sources[$toolGroup->value] = ($previous['groups']->sources[$toolGroup->value] ?? null) === ToolGroupSource::Assistant
                    ? ToolGroupSource::Assistant
                    : ToolGroupSource::CarriedOver;
            }
        }

        return $sources;
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
     * The newest assistant reply: its text, the tool names it called, the
     * groups it loaded (meta.tool_groups) and the groups its tool request
     * asked for.
     *
     * @return array{reply: string, tools: list<string>, groups: ?RoutedToolGroups, requested: list<string>}
     */
    private function previousTurn(?string $conversationId): array
    {
        $none = ['reply' => '', 'tools' => [], 'groups' => null, 'requested' => []];
        $conversationStore = resolve(ConversationStore::class);

        if ($conversationId === null || ! $conversationStore instanceof PaginatesConversations) {
            return $none;
        }

        $last = collect($conversationStore->paginateConversationMessages($conversationId, 4)->items())
            ->first(fn (StoredMessage $storedMessage): bool => $storedMessage->role === 'assistant');

        if (! $last instanceof StoredMessage) {
            return $none;
        }

        $toolCalls = $last->toolCalls();

        return [
            'reply' => $last->content,
            'tools' => array_values(array_unique(array_filter(array_map(
                static fn (array $toolCall): string => (string) ($toolCall['name'] ?? ''),
                $toolCalls,
            )))),
            'groups' => RoutedToolGroups::fromArray($last->meta['tool_groups'] ?? null),
            'requested' => $this->requestedGroups($toolCalls),
        ];
    }

    /**
     * Group values named by the reply's tool requests.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @return list<string>
     */
    private function requestedGroups(array $toolCalls): array
    {
        $requested = [];

        foreach ($toolCalls as $toolCall) {
            if (($toolCall['name'] ?? null) !== self::TOOL_REQUEST_NAME) {
                continue;
            }

            $result = $toolCall['result'] ?? null;
            $decoded = is_string($result) ? json_decode($result, true) : $result;
            $groups = is_array($decoded) ? ($decoded['tool_request']['groups'] ?? null) : null;

            if (! is_array($groups)) {
                continue;
            }

            foreach ($groups as $group) {
                if (is_array($group) && is_string($group['group'] ?? null)) {
                    $requested[] = $group['group'];
                }
            }
        }

        return array_values(array_unique($requested));
    }
}
