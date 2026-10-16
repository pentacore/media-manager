<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Ai\Tools\System\OfferChoicesTool;
use App\Models\ActionRequest;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Derives the cards shown under an assistant reply (choice buttons and
 * inline approvals) from stored or live tool results. The frontend parses
 * the same JSON from the AG-UI stream, so the shapes are a contract.
 */
class ChatCardExtractor
{
    /**
     * @param  iterable<array{id?: string, name?: string, result?: mixed}>  $toolResults
     * @return list<array<string, mixed>>
     */
    public function fromToolResults(iterable $toolResults): array
    {
        $cards = [];
        $choicesToolName = ToolNameResolver::resolve(new OfferChoicesTool);

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
     * Hydrate several card lists (one per message) with a single query.
     *
     * @param  list<list<array<string, mixed>>>  $groups
     * @return list<list<array<string, mixed>>>
     */
    public function hydrateGroups(array $groups): array
    {
        $ids = collect($groups)->flatten(1)
            ->where('type', 'action_request')
            ->pluck('id')
            ->unique()
            ->values();

        $rows = $ids->isEmpty()
            ? collect()
            : ActionRequest::query()->whereIn('id', $ids)->get(['id', 'title', 'status'])->keyBy('id');

        return array_map(fn (array $cards): array => collect($cards)
            ->map(function (array $card) use ($rows): ?array {
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
                ->map(fn (array $option): array => [
                    'label' => (string) ($option['label'] ?? ''),
                    'description' => is_string($option['description'] ?? null) ? $option['description'] : null,
                ])
                ->values()
                ->all(),
        ];
    }
}
