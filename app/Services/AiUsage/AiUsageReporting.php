<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Enums\RateLimitMetric;
use App\Models\AiFreeUsagePool;
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
            $totalCost = max(0.0, $totalCost - $this->freePoolDiscount($since));
        }

        return [
            'total_invocations' => (int) ($row->total_invocations ?? 0),
            'total_tool_calls' => (int) ($row->total_tool_calls ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'total_cost' => number_format($totalCost, 6, '.', ''),
        ];
    }

    /**
     * Per-pool consumption of the configured free quota, each pool sized
     * to its own currently running UTC calendar period. Drives the
     * "Free usage pools" panel.
     *
     * @return array<int, array{id: int, name: string, period: string, unified: bool, documentation_url: string|null, free_input: int|null, free_output: int|null, free_total: int|null, used_input: int, used_output: int, used_total: int, models: array<int, array{provider: string, model: string, used_input: int, used_output: int}>}>
     */
    public function freePoolStatus(): array
    {
        $pools = AiFreeUsagePool::query()->with('prices')->orderBy('name')->get();

        $rows = [];

        foreach ($pools as $pool) {
            $usage = $this->poolUsageRows($pool, $pool->period->currentPeriodStart());

            $models = [];
            $usedInput = 0;
            $usedOutput = 0;

            foreach ($usage as $row) {
                $models[] = [
                    'provider' => (string) $row->provider,
                    'model' => (string) $row->base_model,
                    'used_input' => (int) $row->used_input,
                    'used_output' => (int) $row->used_output,
                ];
                $usedInput += (int) $row->used_input;
                $usedOutput += (int) $row->used_output;
            }

            $rows[] = [
                'id' => $pool->id,
                'name' => $pool->name,
                'period' => $pool->period->value,
                'unified' => $pool->unified,
                'documentation_url' => $pool->documentation_url,
                'free_input' => $pool->free_input_tokens,
                'free_output' => $pool->free_output_tokens,
                'free_total' => $pool->free_total_tokens,
                'used_input' => $usedInput,
                'used_output' => $usedOutput,
                'used_total' => $usedInput + $usedOutput,
                'models' => $models,
            ];
        }

        return $rows;
    }

    /**
     * Per-model consumption against configured provider rate limits, each
     * limit measured over its rolling window (the last minute/hour/day —
     * rolling, unlike the free pools' calendar resets). Token limits count
     * prompt + completion tokens, matching pool accounting. Display only.
     *
     * @return array<int, array{provider: string, model: string, limits: array<int, array{metric: string, period: string, limit_value: int, used: int}>}>
     */
    public function rateLimitStatus(): array
    {
        $prices = AiModelPrice::query()
            ->with('rateLimits')
            ->whereHas('rateLimits')
            ->orderBy('provider')
            ->orderBy('model')
            ->get();

        if ($prices->isEmpty()) {
            return [];
        }

        $periods = $prices
            ->flatMap(fn (AiModelPrice $aiModelPrice) => $aiModelPrice->rateLimits->pluck('period'))
            ->unique();

        // One aggregate query per distinct window length, keyed by
        // provider|base_model (dated suffixes stripped like poolUsageRows).
        $usageByPeriod = [];

        foreach ($periods as $period) {
            $usageByPeriod[$period->value] = DB::table('ai_usage_records')
                ->where('created_at', '>=', $period->windowStart())
                ->whereNotNull('provider')
                ->whereNotNull('model')
                ->selectRaw("
                    provider,
                    regexp_replace(model, '-[0-9]{4}-[0-9]{2}-[0-9]{2}\$', '') AS base_model,
                    COUNT(*) AS requests,
                    COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS tokens
                ")
                ->groupByRaw('provider, base_model')
                ->get()
                ->keyBy(fn (object $row): string => $row->provider.'|'.$row->base_model);
        }

        $rows = [];

        foreach ($prices as $price) {
            $limits = [];

            foreach ($price->rateLimits as $rateLimit) {
                $usage = $usageByPeriod[$rateLimit->period->value][$price->provider.'|'.$price->model] ?? null;

                $limits[] = [
                    'metric' => $rateLimit->metric->value,
                    'period' => $rateLimit->period->value,
                    'limit_value' => $rateLimit->limit_value,
                    'used' => (int) ($rateLimit->metric === RateLimitMetric::Requests
                        ? ($usage->requests ?? 0)
                        : ($usage->tokens ?? 0)),
                ];
            }

            $rows[] = [
                'provider' => $price->provider,
                'model' => $price->model,
                'limits' => $limits,
            ];
        }

        return $rows;
    }

    /**
     * USD value of tokens forgiven by free-usage pools inside the window.
     * A window can span several pool resets (a 30d view over a daily pool
     * crosses ~30 boundaries), so forgiveness is capped per (pool, period
     * bucket): min(bucket usage, pool cap). The forgiven amount converts
     * to USD proportionally across member models by their share of the
     * bucket's usage — pools group same-family models, so exact
     * chronological allocation isn't worth the extra SQL.
     */
    private function freePoolDiscount(CarbonImmutable $since): float
    {
        $pools = AiFreeUsagePool::query()->with('prices')->get();

        $discount = 0.0;

        foreach ($pools as $pool) {
            $rates = [];

            foreach ($pool->prices as $price) {
                $rates[$price->provider.'|'.$price->model] = [
                    'input' => (float) $price->input_per_mtok,
                    'output' => (float) $price->output_per_mtok,
                ];
            }

            if ($rates === []) {
                continue;
            }

            $buckets = $this->poolUsageRows($pool, $since, bucketed: true)
                ->groupBy('bucket');

            foreach ($buckets as $bucket) {
                $bucketInput = (int) $bucket->sum('used_input');
                $bucketOutput = (int) $bucket->sum('used_output');

                if ($pool->unified) {
                    $bucketTotal = $bucketInput + $bucketOutput;
                    $forgivenRatio = $bucketTotal > 0
                        ? min($bucketTotal, (int) ($pool->free_total_tokens ?? 0)) / $bucketTotal
                        : 0.0;

                    foreach ($bucket as $row) {
                        $rate = $rates[$row->provider.'|'.$row->base_model];
                        $discount += ((int) $row->used_input * $rate['input'] + (int) $row->used_output * $rate['output'])
                            * $forgivenRatio / 1_000_000.0;
                    }

                    continue;
                }

                $inputRatio = $bucketInput > 0
                    ? min($bucketInput, (int) ($pool->free_input_tokens ?? 0)) / $bucketInput
                    : 0.0;
                $outputRatio = $bucketOutput > 0
                    ? min($bucketOutput, (int) ($pool->free_output_tokens ?? 0)) / $bucketOutput
                    : 0.0;

                foreach ($bucket as $row) {
                    $rate = $rates[$row->provider.'|'.$row->base_model];
                    $discount += (int) $row->used_input * $rate['input'] * $inputRatio / 1_000_000.0;
                    $discount += (int) $row->used_output * $rate['output'] * $outputRatio / 1_000_000.0;
                }
            }
        }

        return $discount;
    }

    /**
     * Input/output token sums for a pool's member models since $since,
     * grouped by (provider, base model[, period bucket]). Base model =
     * recorded model id with any trailing -YYYY-MM-DD suffix stripped, so
     * dated variants match their catalog row.
     *
     * @return Collection<int, object{provider: string, base_model: string, bucket?: string, used_input: int|string, used_output: int|string}>
     */
    private function poolUsageRows(AiFreeUsagePool $aiFreeUsagePool, CarbonImmutable $since, bool $bucketed = false): Collection
    {
        $memberKeys = $aiFreeUsagePool->prices
            ->map(fn (AiModelPrice $aiModelPrice): string => $aiModelPrice->provider.'|'.$aiModelPrice->model)
            ->all();

        if ($memberKeys === []) {
            return new Collection;
        }

        $bucketSelect = $bucketed
            ? sprintf(", date_trunc('%s', ai_usage_records.created_at) AS bucket", $aiFreeUsagePool->period->sqlDateTrunc())
            : '';

        $groupBy = ['provider', 'base_model'];

        if ($bucketed) {
            $groupBy[] = 'bucket';
        }

        return DB::table('ai_usage_records')
            ->where('ai_usage_records.created_at', '>=', $since)
            ->whereNotNull('ai_usage_records.provider')
            ->whereNotNull('ai_usage_records.model')
            ->selectRaw("
                ai_usage_records.provider,
                regexp_replace(ai_usage_records.model, '-[0-9]{4}-[0-9]{2}-[0-9]{2}\$', '') AS base_model,
                COALESCE(SUM(ai_usage_records.prompt_tokens), 0) AS used_input,
                COALESCE(SUM(ai_usage_records.completion_tokens), 0) AS used_output
                {$bucketSelect}
            ")
            ->groupByRaw(implode(', ', $groupBy))
            ->get()
            ->filter(fn (object $row): bool => in_array($row->provider.'|'.$row->base_model, $memberKeys, true))
            ->values();
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
