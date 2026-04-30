<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Models\AiModelPrice;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AiUsageReporting
{
    private const array AGGREGATABLE_COLUMNS = ['model', 'provider'];

    private const string TOKEN_SUM_EXPR = '
        ai_usage_records.prompt_tokens
        + ai_usage_records.completion_tokens
        + ai_usage_records.cache_read_input_tokens
        + ai_usage_records.cache_write_input_tokens
        + ai_usage_records.reasoning_tokens
    ';

    /**
     * @return array{total_invocations: int, total_tool_calls: int, total_tokens: int, total_cost: string}
     */
    public function totals(CarbonImmutable $since, ?Scenario $scenario = null): array
    {
        [$costSql, $costBindings] = $this->costExpression($scenario);

        $row = $this->query($since, $scenario)
            ->selectRaw('
                COUNT(*) AS total_invocations,
                COALESCE(SUM(ai_usage_records.tool_calls_count), 0) AS total_tool_calls,
                COALESCE(SUM('.self::TOKEN_SUM_EXPR.'), 0) AS total_tokens,
                COALESCE(SUM('.$costSql.'), 0) AS total_cost
            ', $costBindings)
            ->first();

        $totalCost = (float) ($row->total_cost ?? 0);

        // Free-tier subtraction is meaningless under a scenario projection
        // (the user is asking "what if rates were X?", not "what would I
        // bill?"), so only net out the included tokens for the live view.
        if (! $scenario instanceof Scenario) {
            $totalCost = max(0.0, $totalCost - $this->freeTierDiscount($since));
        }

        return [
            'total_invocations' => (int) ($row->total_invocations ?? 0),
            'total_tool_calls' => (int) ($row->total_tool_calls ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'total_cost' => number_format($totalCost, 6, '.', ''),
        ];
    }

    /**
     * Per-(provider, model) breakdown of how much of each model's
     * configured free monthly quota has been consumed in the window.
     * Drives the "Free tier" panel + the net total cost computation.
     *
     * @return array<int, array{provider: string, model: string, used_input: int, used_output: int, free_input: int, free_output: int}>
     */
    public function freeTierStatus(CarbonImmutable $since): array
    {
        $usage = DB::table('ai_usage_records')
            ->where('ai_usage_records.created_at', '>=', $since)
            ->whereNotNull('ai_usage_records.provider')
            ->whereNotNull('ai_usage_records.model')
            ->selectRaw('
                ai_usage_records.provider,
                ai_usage_records.model,
                COALESCE(SUM(ai_usage_records.prompt_tokens), 0) AS used_input,
                COALESCE(SUM(ai_usage_records.completion_tokens), 0) AS used_output
            ')
            ->groupBy('ai_usage_records.provider', 'ai_usage_records.model')
            ->get();

        $rows = [];

        foreach ($usage as $row) {
            $price = AiModelPrice::query()
                ->where('provider', $row->provider)
                ->where('model', preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', (string) $row->model))
                ->first();

            $freeInput = $price?->free_input_tokens_per_month;
            $freeOutput = $price?->free_output_tokens_per_month;

            // Skip rows with no configured quota — there's nothing useful
            // to report and the panel would just be noise.
            if ($freeInput === null && $freeOutput === null) {
                continue;
            }

            $rows[] = [
                'provider' => (string) $row->provider,
                'model' => (string) $row->model,
                'used_input' => (int) $row->used_input,
                'used_output' => (int) $row->used_output,
                'free_input' => (int) ($freeInput ?? 0),
                'free_output' => (int) ($freeOutput ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Sum of "tokens that fell under the free quota × catalog rate" for
     * every priced model in the window. Subtracted from the gross cost
     * so the displayed Spend reflects what the provider would actually
     * bill.
     */
    private function freeTierDiscount(CarbonImmutable $since): float
    {
        $usage = DB::table('ai_usage_records')
            ->where('ai_usage_records.created_at', '>=', $since)
            ->whereNotNull('ai_usage_records.provider')
            ->whereNotNull('ai_usage_records.model')
            ->selectRaw('
                ai_usage_records.provider,
                ai_usage_records.model,
                COALESCE(SUM(ai_usage_records.prompt_tokens), 0) AS used_input,
                COALESCE(SUM(ai_usage_records.completion_tokens), 0) AS used_output
            ')
            ->groupBy('ai_usage_records.provider', 'ai_usage_records.model')
            ->get();

        $discount = 0.0;

        foreach ($usage as $row) {
            $price = AiModelPrice::query()
                ->where('provider', $row->provider)
                ->where('model', preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', (string) $row->model))
                ->first();

            if (! $price instanceof AiModelPrice) {
                continue;
            }

            $freeInput = (int) ($price->free_input_tokens_per_month ?? 0);
            $freeOutput = (int) ($price->free_output_tokens_per_month ?? 0);

            $forgivenInput = min((int) $row->used_input, $freeInput);
            $forgivenOutput = min((int) $row->used_output, $freeOutput);

            $discount += $forgivenInput * (float) $price->input_per_mtok / 1_000_000.0;
            $discount += $forgivenOutput * (float) $price->output_per_mtok / 1_000_000.0;
        }

        return $discount;
    }

    /**
     * @return Collection<int, object{key: string|null, invocations: int, total_tokens: int, total_cost: string}>
     */
    public function aggregateBy(string $column, CarbonImmutable $since, ?Scenario $scenario = null): Collection
    {
        throw_unless(in_array($column, self::AGGREGATABLE_COLUMNS, true), InvalidArgumentException::class, sprintf("Cannot aggregate by '%s'.", $column));

        [$costSql, $costBindings] = $this->costExpression($scenario);

        return $this->query($since, $scenario)
            ->selectRaw("
                ai_usage_records.{$column} AS key,
                COUNT(*) AS invocations,
                COALESCE(SUM(".self::TOKEN_SUM_EXPR.'), 0) AS total_tokens,
                COALESCE(SUM('.$costSql.'), 0) AS total_cost
            ', $costBindings)
            ->groupBy('ai_usage_records.'.$column)
            ->orderByDesc('total_cost')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function recentInvocations(CarbonImmutable $since, ?Scenario $scenario = null, int $limit = 50): Collection
    {
        [$costSql, $costBindings] = $this->costExpression($scenario);

        return $this->query($since, $scenario)
            ->leftJoin('users', 'ai_usage_records.user_id', '=', 'users.id')
            ->selectRaw('
                ai_usage_records.id,
                ai_usage_records.created_at,
                ai_usage_records.provider,
                ai_usage_records.model,
                ai_usage_records.prompt_tokens,
                ai_usage_records.completion_tokens,
                ai_usage_records.tool_calls_count,
                ai_usage_records.conversation_id,
                ai_usage_records.status,
                users.name AS user_name,
                ('.self::TOKEN_SUM_EXPR.') AS total_tokens,
                ('.$costSql.') AS cost
            ', $costBindings)
            ->latest('ai_usage_records.created_at')
            ->limit($limit)
            ->get()
            // The DB driver returns created_at as a naive timestamp string;
            // serialize it as an ISO 8601 UTC value so the browser can
            // convert it to the viewer's local timezone instead of treating
            // it as already-local.
            ->map(function (object $row): object {
                if (isset($row->created_at) && is_string($row->created_at)) {
                    $row->created_at = CarbonImmutable::parse($row->created_at, 'UTC')
                        ->toIso8601String();
                }

                return $row;
            });
    }

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
     *     rates: array{
     *         source: 'snapshot'|'catalog'|'unpriced',
     *         input_per_mtok: float,
     *         output_per_mtok: float,
     *         cache_read_per_mtok: float,
     *         cache_write_per_mtok: float,
     *         reasoning_per_mtok: float
     *     },
     *     breakdown: array<int, array{label: string, tokens: int, rate: float, cost: float}>,
     *     total_cost: float,
     *     scenario_breakdown: array<int, array{label: string, tokens: int, rate: float, cost: float}>|null,
     *     scenario_total_cost: float|null
     * }
     */
    public function invocationDetail(AiUsageRecord $aiUsageRecord, ?Scenario $scenario = null): array
    {
        $aiUsageRecord->loadMissing('user');

        $catalog = AiModelPrice::query()
            ->where('provider', $aiUsageRecord->provider)
            ->where('model', preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', (string) $aiUsageRecord->model))
            ->first();

        $rates = [
            'input_per_mtok' => $this->resolveRate($aiUsageRecord->input_per_mtok, $catalog?->input_per_mtok),
            'output_per_mtok' => $this->resolveRate($aiUsageRecord->output_per_mtok, $catalog?->output_per_mtok),
            'cache_read_per_mtok' => $this->resolveRate($aiUsageRecord->cache_read_per_mtok, $catalog?->cache_read_per_mtok),
            'cache_write_per_mtok' => $this->resolveRate($aiUsageRecord->cache_write_per_mtok, $catalog?->cache_write_per_mtok),
            'reasoning_per_mtok' => $this->resolveRate($aiUsageRecord->reasoning_per_mtok, $catalog?->reasoning_per_mtok),
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
        ];

        $breakdown = $this->buildBreakdown($tokens, [
            'input' => $rates['input_per_mtok'],
            'output' => $rates['output_per_mtok'],
            'cache_read' => $rates['cache_read_per_mtok'],
            'cache_write' => $rates['cache_write_per_mtok'],
            'reasoning' => $rates['reasoning_per_mtok'],
        ]);

        $tools = AiToolInvocation::query()
            ->where('invocation_id', $aiUsageRecord->invocation_id)
            ->orderBy('id')
            ->get(['id', 'tool_class', 'tool_invocation_id', 'status', 'created_at'])
            ->map(fn (AiToolInvocation $aiToolInvocation): array => [
                'id' => $aiToolInvocation->id,
                'tool_class' => $aiToolInvocation->tool_class,
                'tool_invocation_id' => $aiToolInvocation->tool_invocation_id,
                'status' => $aiToolInvocation->status,
                'created_at' => $aiToolInvocation->created_at?->toIso8601String(),
            ])
            ->all();

        [$scenarioBreakdown, $scenarioTotal] = $this->scenarioBreakdown($tokens, $scenario);

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
                'response_text' => $aiUsageRecord->response_text,
                'price_source' => $aiUsageRecord->price_source,
                'conversation_id' => $aiUsageRecord->conversation_id,
                'status' => $aiUsageRecord->status,
                'created_at' => $aiUsageRecord->created_at?->toIso8601String(),
            ],
            'user' => $aiUsageRecord->user instanceof User ? [
                'id' => $aiUsageRecord->user->id,
                'name' => $aiUsageRecord->user->name,
            ] : null,
            'tools' => $tools,
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
     * @param  array<string, int>  $tokens
     * @param  array<string, float>  $rates
     * @return array<int, array{label: string, tokens: int, rate: float, cost: float}>
     */
    private function buildBreakdown(array $tokens, array $rates): array
    {
        $labels = [
            'input' => 'Input',
            'output' => 'Output',
            'cache_read' => 'Cache read',
            'cache_write' => 'Cache write',
            'reasoning' => 'Reasoning',
        ];

        $rows = [];

        foreach ($labels as $key => $label) {
            $rows[] = [
                'label' => $label,
                'tokens' => $tokens[$key],
                'rate' => $rates[$key],
                'cost' => $tokens[$key] * $rates[$key] / 1_000_000,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $tokens
     * @return array{0: array<int, array{label: string, tokens: int, rate: float, cost: float}>|null, 1: float|null}
     */
    private function scenarioBreakdown(array $tokens, ?Scenario $scenario): array
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
        ]);

        return [$breakdown, array_sum(array_column($breakdown, 'cost'))];
    }

    /**
     * Returns SQL expression + bindings for the cost-per-row computation.
     *
     * - Without scenario: prefers the per-row snapshot rates captured at call
     *   time (or assigned retroactively); falls back to live ai_model_prices
     *   when the snapshot is null. Cost is zero for rows that match neither.
     * - With scenario: uses the scenario's flat rates uniformly across all
     *   rows, ignoring both snapshot and catalog.
     *
     * @return array{0: string, 1: array<int, float>}
     */
    private function costExpression(?Scenario $scenario): array
    {
        if (! $scenario instanceof Scenario) {
            return [
                '
                    (
                        ai_usage_records.prompt_tokens * COALESCE(ai_usage_records.input_per_mtok, ai_model_prices.input_per_mtok, 0)
                        + ai_usage_records.completion_tokens * COALESCE(ai_usage_records.output_per_mtok, ai_model_prices.output_per_mtok, 0)
                        + ai_usage_records.cache_read_input_tokens * COALESCE(ai_usage_records.cache_read_per_mtok, ai_model_prices.cache_read_per_mtok, 0)
                        + ai_usage_records.cache_write_input_tokens * COALESCE(ai_usage_records.cache_write_per_mtok, ai_model_prices.cache_write_per_mtok, 0)
                        + ai_usage_records.reasoning_tokens * COALESCE(ai_usage_records.reasoning_per_mtok, ai_model_prices.reasoning_per_mtok, 0)
                    ) / 1000000.0
                ',
                [],
            ];
        }

        // Cast each rate parameter to numeric. Postgres infers parameter
        // types from context, and the surrounding integer columns
        // (prompt_tokens etc.) make it default to integer — which fails
        // hard on fractional rates like 0.75. Explicit ::numeric forces a
        // decimal-friendly type regardless of whether PHP sends the value
        // as a float or a string.
        return [
            '
                (
                    ai_usage_records.prompt_tokens * ?::numeric
                    + ai_usage_records.completion_tokens * ?::numeric
                    + ai_usage_records.cache_read_input_tokens * ?::numeric
                    + ai_usage_records.cache_write_input_tokens * ?::numeric
                    + ai_usage_records.reasoning_tokens * ?::numeric
                ) / 1000000.0
            ',
            [
                $scenario->inputPerMtok,
                $scenario->outputPerMtok,
                $scenario->cacheReadPerMtok,
                $scenario->cacheWritePerMtok,
                $scenario->reasoningPerMtok,
            ],
        ];
    }

    private function query(CarbonImmutable $since, ?Scenario $scenario = null): Builder
    {
        $builder = DB::table('ai_usage_records');

        // Only join the price table when no scenario is in play. With a
        // scenario, rates come from the scenario itself, so the join is
        // unnecessary and would also break orderByDesc('total_cost') in some
        // edge cases by influencing row counts.
        // Match on provider + base model name. A "base" name is the model id
        // recorded on the usage row with any trailing date-version suffix
        // (e.g. "-2025-09-23") stripped, so storing pricing for "gpt-5-mini"
        // covers both "gpt-5-mini" and dated variants like
        // "gpt-5-mini-2025-09-23". An exact match still wins because the
        // stripped value equals the unstripped value when no suffix exists.
        if (! $scenario instanceof Scenario) {
            $builder->leftJoin('ai_model_prices', function ($join): void {
                $join->on('ai_usage_records.provider', '=', 'ai_model_prices.provider')
                    ->whereRaw(
                        "regexp_replace(ai_usage_records.model, '-[0-9]{4}-[0-9]{2}-[0-9]{2}$', '') = ai_model_prices.model"
                    );
            });
        }

        return $builder->where('ai_usage_records.created_at', '>=', $since);
    }
}
