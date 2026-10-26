<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Ai\Tools\System\OfferChoicesTool;
use App\Ai\Tools\System\RequestToolsTool;
use App\Models\ActionRequest;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Derives the cards shown under an assistant reply (choice buttons, tool-group
 * requests, inline approvals and subtitle audit batches) from stored or live
 * tool results. The frontend parses the same JSON from the AG-UI stream, so
 * the shapes are a contract.
 */
class ChatCardExtractor
{
    /**
     * Reads live tool results (`ToolResult::toArray()`) and stored tool calls
     * alike: the conversation store keeps each step's results folded into its
     * `tool_calls` entries, so both carry `id`, `name` and `result` (a call
     * still awaiting approval has no `result` and yields no card).
     *
     * @param  iterable<array{id?: string, name?: string, result?: mixed}>  $toolResults
     * @return list<array<string, mixed>>
     */
    public function fromToolResults(iterable $toolResults): array
    {
        $cards = [];
        $choicesToolName = ToolNameResolver::resolve(new OfferChoicesTool);
        $toolRequestName = ToolNameResolver::resolve(new RequestToolsTool);

        foreach ($toolResults as $toolResult) {
            $result = $toolResult['result'] ?? null;

            if (is_string($result)) {
                $result = json_decode($result, true);
            }

            if (! is_array($result)) {
                continue;
            }

            if (($toolResult['name'] ?? null) === $choicesToolName && is_array($result['choices'] ?? null)) {
                $cards[] = $this->choicesCard((string) ($toolResult['id'] ?? ''), $result['choices']);

                continue;
            }

            if (($toolResult['name'] ?? null) === $toolRequestName && is_array($result['tool_request'] ?? null)) {
                $card = $this->toolRequestCard((string) ($toolResult['id'] ?? ''), $result['tool_request']);

                if ($card !== null) {
                    $cards[] = $card;
                }

                continue;
            }

            if ($this->isSubtitleAuditBatchCard($result['subtitle_audit_batch'] ?? null)) {
                $cards[] = $result['subtitle_audit_batch'];

                continue;
            }

            if (($result['queued'] ?? null) === true && is_int($result['action_request_id'] ?? null)) {
                $cards[] = [
                    'type' => 'action_request',
                    'id' => $result['action_request_id'],
                    'title' => is_string($result['title'] ?? null) ? $result['title'] : null,
                    'status' => (string) ($result['status'] ?? ''),
                    'requires_approval' => (bool) ($result['requires_approval'] ?? false),
                ];
            }
        }

        return $cards;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    public function hydrate(array $cards): array
    {
        return $this->hydrateGroups([$cards])[0];
    }

    /**
     * Hydrate several card lists (one per message) with one query per card
     * kind: action cards take their request's live title and status, batch
     * cards are presented afresh. A card whose row is gone is dropped.
     *
     * @param  list<list<array<string, mixed>>>  $groups
     * @return list<list<array<string, mixed>>>
     */
    public function hydrateGroups(array $groups): array
    {
        $cards = collect($groups)->flatten(1);

        $ids = $cards->where('type', 'action_request')
            ->pluck('id')
            ->unique()
            ->values();

        $rows = $ids->isEmpty()
            ? collect()
            : ActionRequest::query()->whereIn('id', $ids)->get(['id', 'title', 'status'])->keyBy('id');

        $batches = SubtitleAuditBatchCard::presentMany($cards->where('type', SubtitleAuditBatchCard::TYPE)
            ->pluck('id')
            ->unique()
            ->values()
            ->all());

        return array_map(fn (array $cards): array => collect($cards)
            ->map(function (array $card) use ($rows, $batches): ?array {
                if ($card['type'] === SubtitleAuditBatchCard::TYPE) {
                    return $batches[$card['id']] ?? null;
                }

                if ($card['type'] !== 'action_request') {
                    return $card;
                }

                $row = $rows->get($card['id']);

                return $row === null ? null : [
                    ...$card,
                    'title' => $row->title ?? $card['title'],
                    'status' => $row->status->value,
                ];
            })
            ->filter()
            ->values()
            ->all(), $groups);
    }

    /**
     * A repair result's card: the presenter's payload, recognised by its type and int id.
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private function isSubtitleAuditBatchCard(mixed $value): bool
    {
        return is_array($value)
            && ($value['type'] ?? null) === SubtitleAuditBatchCard::TYPE
            && is_int($value['id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $choices
     * @return array<string, mixed>
     */
    private function choicesCard(string $id, array $choices): array
    {
        return [
            'type' => 'choices',
            'id' => $id,
            'question' => (string) ($choices['question'] ?? ''),
            'multiple' => (bool) ($choices['multiple'] ?? false),
            'options' => collect(is_array($choices['options'] ?? null) ? $choices['options'] : [])
                ->filter(fn (mixed $option): bool => is_array($option))
                ->map(fn (array $option): array => [
                    'label' => (string) ($option['label'] ?? ''),
                    'description' => is_string($option['description'] ?? null) ? $option['description'] : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $toolRequest
     * @return array<string, mixed>|null
     */
    private function toolRequestCard(string $id, array $toolRequest): ?array
    {
        $groups = collect(is_array($toolRequest['groups'] ?? null) ? $toolRequest['groups'] : [])
            ->filter(fn (mixed $group): bool => is_array($group) && is_string($group['group'] ?? null))
            ->map(fn (array $group): array => [
                'group' => $group['group'],
                'label' => is_string($group['label'] ?? null) ? $group['label'] : $group['group'],
            ])
            ->values()
            ->all();

        if ($groups === []) {
            return null;
        }

        return [
            'type' => 'tool_request',
            'id' => $id,
            'groups' => $groups,
            'reason' => (string) ($toolRequest['reason'] ?? ''),
        ];
    }
}
