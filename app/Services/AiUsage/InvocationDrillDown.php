<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Models\AiModelPrice;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\User;

/**
 * The admin usage drill-down for one invocation: tokens, the rates that
 * priced it and their source, the cost breakdown, its tools and sub-agent
 * runs, and an optional scenario recompute.
 */
class InvocationDrillDown
{
    /**
     * Per-invocation detail for the admin drill-down: token counts, the
     * pricing source actually used to cost it, the breakdown that produced
     * the total, the tools the agent called, plus an optional scenario
     * recompute. The catalog rate falls back from the snapshot when the
     * snapshot is null, mirroring costExpression()'s COALESCE chain.
     *
     * @return array{
     *     record: array<string, mixed>,
     *     user: array{id: int, name: string}|null,
     *     tools: array<int, array<string, mixed>>,
     *     children: list<array{id: int, agent_class: string|null, model: string|null, status: string, total_tokens: int}>,
     *     rates: array{
     *         source: 'snapshot'|'catalog'|'unpriced',
     *         input_per_mtok: float,
     *         output_per_mtok: float,
     *         cache_read_per_mtok: float,
     *         cache_write_per_mtok: float,
     *         reasoning_per_mtok: float,
     *         search_unit_per_k: float
     *     },
     *     breakdown: array<int, array{label: string, tokens: int|float, rate: float, cost: float}>,
     *     total_cost: float,
     *     scenario_breakdown: array<int, array{label: string, tokens: int|float, rate: float, cost: float}>|null,
     *     scenario_total_cost: float|null
     * }
     */
    public function forRecord(AiUsageRecord $aiUsageRecord, ?Scenario $scenario = null): array
    {
        $aiUsageRecord->loadMissing('user');

        $catalog = AiModelPrice::query()
            ->where('provider', $aiUsageRecord->provider)
            ->where('model', BaseModelName::of((string) $aiUsageRecord->model))
            ->first();

        $rates = [
            'input_per_mtok' => $this->resolveRate($aiUsageRecord->input_per_mtok, $catalog?->input_per_mtok),
            'output_per_mtok' => $this->resolveRate($aiUsageRecord->output_per_mtok, $catalog?->output_per_mtok),
            'cache_read_per_mtok' => $this->resolveRate($aiUsageRecord->cache_read_per_mtok, $catalog?->cache_read_per_mtok),
            'cache_write_per_mtok' => $this->resolveRate($aiUsageRecord->cache_write_per_mtok, $catalog?->cache_write_per_mtok),
            'reasoning_per_mtok' => $this->resolveRate($aiUsageRecord->reasoning_per_mtok, $catalog?->reasoning_per_mtok),
            'search_unit_per_k' => $this->resolveRate($aiUsageRecord->search_unit_per_k, $catalog?->search_unit_per_k),
        ];

        $rateSource = match (true) {
            $aiUsageRecord->input_per_mtok !== null => 'snapshot',
            $catalog instanceof AiModelPrice => 'catalog',
            default => 'unpriced',
        };

        $tokens = [
            'input' => $aiUsageRecord->prompt_tokens,
            'output' => $aiUsageRecord->completion_tokens,
            'cache_read' => $aiUsageRecord->cache_read_input_tokens,
            'cache_write' => $aiUsageRecord->cache_write_input_tokens,
            'reasoning' => $aiUsageRecord->reasoning_tokens,
            'search_units' => (float) $aiUsageRecord->search_units,
        ];

        $breakdown = $this->buildBreakdown($tokens, [
            'input' => $rates['input_per_mtok'],
            'output' => $rates['output_per_mtok'],
            'cache_read' => $rates['cache_read_per_mtok'],
            'cache_write' => $rates['cache_write_per_mtok'],
            'reasoning' => $rates['reasoning_per_mtok'],
            'search_units' => $rates['search_unit_per_k'],
        ]);

        $tools = AiToolInvocation::query()
            ->where('invocation_id', $aiUsageRecord->invocation_id)
            ->orderBy('id')
            ->get(['id', 'tool_class', 'tool_invocation_id', 'status', 'error_code', 'duration_ms', 'created_at'])
            ->map(fn (AiToolInvocation $aiToolInvocation): array => [
                'id' => $aiToolInvocation->id,
                'tool_class' => $aiToolInvocation->tool_class,
                'tool_invocation_id' => $aiToolInvocation->tool_invocation_id,
                'status' => $aiToolInvocation->status,
                'error_code' => $aiToolInvocation->error_code,
                'duration_ms' => $aiToolInvocation->duration_ms,
                'created_at' => $aiToolInvocation->created_at?->toIso8601String(),
            ])
            ->all();

        $children = AiUsageRecord::query()
            ->where('parent_invocation_id', $aiUsageRecord->invocation_id)
            ->orderBy('id')
            ->get()
            ->map(fn (AiUsageRecord $child): array => [
                'id' => $child->id,
                'agent_class' => $child->agent_class,
                'model' => $child->model,
                'status' => $child->status,
                'total_tokens' => $child->prompt_tokens
                    + $child->completion_tokens
                    + $child->cache_read_input_tokens
                    + $child->cache_write_input_tokens
                    + $child->reasoning_tokens,
            ])
            ->values()
            ->all();

        [$scenarioBreakdown, $scenarioTotal] = $this->scenarioBreakdown($tokens, $scenario, (float) ($aiUsageRecord->search_unit_per_k ?? 0));

        return [
            'record' => [
                'id' => $aiUsageRecord->id,
                'invocation_id' => $aiUsageRecord->invocation_id,
                'agent_class' => $aiUsageRecord->agent_class,
                'provider' => $aiUsageRecord->provider,
                'model' => $aiUsageRecord->model,
                'prompt_tokens' => $aiUsageRecord->prompt_tokens,
                'completion_tokens' => $aiUsageRecord->completion_tokens,
                'cache_read_input_tokens' => $aiUsageRecord->cache_read_input_tokens,
                'cache_write_input_tokens' => $aiUsageRecord->cache_write_input_tokens,
                'reasoning_tokens' => $aiUsageRecord->reasoning_tokens,
                'tool_calls_count' => $aiUsageRecord->tool_calls_count,
                'prompt_text' => $aiUsageRecord->prompt_text,
                'response_text' => $aiUsageRecord->response_text,
                'price_source' => $aiUsageRecord->price_source,
                'conversation_id' => $aiUsageRecord->conversation_id,
                'status' => $aiUsageRecord->status,
                'kind' => $aiUsageRecord->kind->value,
                'error_message' => $aiUsageRecord->error_message,
                'parent_invocation_id' => $aiUsageRecord->parent_invocation_id,
                'search_units' => (float) $aiUsageRecord->search_units,
                'created_at' => $aiUsageRecord->created_at?->toIso8601String(),
            ],
            'user' => $aiUsageRecord->user instanceof User ? [
                'id' => $aiUsageRecord->user->id,
                'name' => $aiUsageRecord->user->name,
            ] : null,
            'tools' => $tools,
            'children' => $children,
            'rates' => array_merge(['source' => $rateSource], $rates),
            'breakdown' => $breakdown,
            'total_cost' => array_sum(array_column($breakdown, 'cost')),
            'scenario_breakdown' => $scenarioBreakdown,
            'scenario_total_cost' => $scenarioTotal,
        ];
    }

    private function resolveRate(?string $snapshot, ?string $catalog): float
    {
        if ($snapshot !== null) {
            return (float) $snapshot;
        }

        if ($catalog !== null) {
            return (float) $catalog;
        }

        return 0.0;
    }

    /**
     * Token tiers are priced per million; search units per thousand. The
     * search-units line appears only for rows that billed search units, so
     * token-only runs keep their five-tier breakdown.
     *
     * @param  array<string, int|float>  $tokens
     * @param  array<string, float>  $rates
     * @return array<int, array{label: string, tokens: int|float, rate: float, cost: float}>
     */
    private function buildBreakdown(array $tokens, array $rates): array
    {
        $labels = [
            'input' => 'Input',
            'output' => 'Output',
            'cache_read' => 'Cache read',
            'cache_write' => 'Cache write',
            'reasoning' => 'Reasoning',
            'search_units' => 'Search units',
        ];

        $rows = [];

        foreach ($labels as $key => $label) {
            if ($key === 'search_units' && (float) ($tokens[$key] ?? 0) <= 0.0) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'tokens' => $tokens[$key],
                'rate' => $rates[$key],
                'cost' => $tokens[$key] * $rates[$key] / ($key === 'search_units' ? 1_000 : 1_000_000),
            ];
        }

        return $rows;
    }

    /**
     * Scenarios model token rates only; search units keep the row's
     * snapshotted per-1k rate, mirroring costExpression().
     *
     * @param  array<string, int|float>  $tokens
     * @return array{0: array<int, array{label: string, tokens: int|float, rate: float, cost: float}>|null, 1: float|null}
     */
    private function scenarioBreakdown(array $tokens, ?Scenario $scenario, float $searchUnitPerK): array
    {
        if (! $scenario instanceof Scenario) {
            return [null, null];
        }

        $breakdown = $this->buildBreakdown($tokens, [
            'input' => $scenario->inputPerMtok,
            'output' => $scenario->outputPerMtok,
            'cache_read' => $scenario->cacheReadPerMtok,
            'cache_write' => $scenario->cacheWritePerMtok,
            'reasoning' => $scenario->reasoningPerMtok,
            'search_units' => $searchUnitPerK,
        ]);

        return [$breakdown, array_sum(array_column($breakdown, 'cost'))];
    }
}
