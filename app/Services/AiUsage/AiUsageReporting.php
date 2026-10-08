<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Enums\AiUsageKind;
use App\Models\AiUsageRecord;
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

    public function __construct(
        private readonly FreePoolAccounting $freePoolAccounting,
        private readonly RateLimitUsage $rateLimitUsage,
        private readonly InvocationDrillDown $invocationDrillDown,
    ) {}

    /**
     * A null $since means no lower bound (the "All" window); a null $kind
     * spans every usage kind (the budget guard relies on that default).
     *
     * @return array{total_invocations: int, total_tool_calls: int, total_tokens: int, total_cost: string}
     */
    public function totals(?CarbonImmutable $since, ?Scenario $scenario = null, ?AiUsageKind $kind = null): array
    {
        [$costSql, $costBindings] = $this->costExpression($scenario);

        $row = $this->query($since, $scenario, $kind)
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
            $totalCost = max(0.0, $totalCost - $this->freePoolAccounting->discount($since, $kind));
        }

        return [
            'total_invocations' => (int) ($row->total_invocations ?? 0),
            'total_tool_calls' => (int) ($row->total_tool_calls ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'total_cost' => number_format($totalCost, 6, '.', ''),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, period: string, unified: bool, documentation_url: string|null, free_input: int|null, free_output: int|null, free_total: int|null, used_input: int, used_output: int, used_total: int, models: array<int, array{provider: string, model: string, used_input: int, used_output: int}>}>
     */
    public function freePoolStatus(): array
    {
        return $this->freePoolAccounting->status();
    }

    /**
     * Strip a trailing date-version suffix off a model id so dated variants
     * match their catalog row.
     */
    public static function baseModel(string $model): string
    {
        return BaseModelName::of($model);
    }

    /**
     * @return array<int, array{provider: string, model: string, limits: array<int, array{metric: string, period: string, limit_value: int, used: int}>}>
     */
    public function rateLimitStatus(): array
    {
        return $this->rateLimitUsage->status();
    }

    /**
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
    public function invocationDetail(AiUsageRecord $aiUsageRecord, ?Scenario $scenario = null): array
    {
        return $this->invocationDrillDown->forRecord($aiUsageRecord, $scenario);
    }

    /**
     * @return Collection<int, object{key: string|null, invocations: int, total_tokens: int, total_cost: string}>
     */
    public function aggregateBy(string $column, ?CarbonImmutable $since, ?Scenario $scenario = null, ?AiUsageKind $kind = null): Collection
    {
        throw_unless(in_array($column, self::AGGREGATABLE_COLUMNS, true), InvalidArgumentException::class, sprintf("Cannot aggregate by '%s'.", $column));

        [$costSql, $costBindings] = $this->costExpression($scenario);

        return $this->query($since, $scenario, $kind)
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
     * @param  bool|null  $fellThrough  null: every row; false: runs that used their task's first tier; true: runs that fell through to a lower tier
     * @return Collection<int, object>
     */
    public function recentInvocations(?CarbonImmutable $since, ?Scenario $scenario = null, int $limit = 50, ?AiUsageKind $kind = null, ?bool $fellThrough = null): Collection
    {
        [$costSql, $costBindings] = $this->costExpression($scenario);

        return $this->query($since, $scenario, $kind)
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
                ai_usage_records.kind,
                ai_usage_records.error_message,
                ai_usage_records.tier_position,
                users.name AS user_name,
                ('.self::TOKEN_SUM_EXPR.') AS total_tokens,
                ('.$costSql.') AS cost
            ', $costBindings)
            ->when($fellThrough === true, fn (Builder $builder) => $builder->where('ai_usage_records.tier_position', '>=', 2))
            ->when($fellThrough === false, fn (Builder $builder) => $builder->where('ai_usage_records.tier_position', 1))
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
     * Per-tool call counts, failures and latency percentiles inside the
     * window. Percentiles skip rows recorded before duration tracking
     * existed (null duration_ms), so they are null for such tools.
     *
     * @return Collection<int, object{tool_class: string, calls: int, failures: int, p50_ms: int|null, p95_ms: int|null}>
     */
    public function toolStats(?CarbonImmutable $since): Collection
    {
        return DB::table('ai_tool_invocations')
            ->when($since instanceof CarbonImmutable, fn (Builder $builder) => $builder->where('created_at', '>=', $since))
            ->selectRaw("
                tool_class,
                COUNT(*) AS calls,
                COUNT(*) FILTER (WHERE status = 'failed') AS failures,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY duration_ms) AS p50_ms,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95_ms
            ")
            ->groupBy('tool_class')
            ->orderByDesc('calls')
            ->orderBy('tool_class')
            ->get()
            ->map(fn (object $row): object => (object) [
                'tool_class' => (string) $row->tool_class,
                'calls' => (int) $row->calls,
                'failures' => (int) $row->failures,
                'p50_ms' => $row->p50_ms === null ? null : (int) round((float) $row->p50_ms),
                'p95_ms' => $row->p95_ms === null ? null : (int) round((float) $row->p95_ms),
            ]);
    }

    /**
     * Returns SQL expression + bindings for the cost-per-row computation.
     *
     * - Without scenario: prefers the per-row snapshot rates captured at call
     *   time (or assigned retroactively); falls back to live ai_model_prices
     *   when the snapshot is null. Cost is zero for rows that match neither.
     * - With scenario: uses the scenario's flat rates uniformly across all
     *   rows, ignoring both snapshot and catalog. Scenarios model token
     *   rates only, so reranking search units keep their snapshot rate.
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
                    + ai_usage_records.search_units * COALESCE(ai_usage_records.search_unit_per_k, ai_model_prices.search_unit_per_k, 0) / 1000.0
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
                + ai_usage_records.search_units * COALESCE(ai_usage_records.search_unit_per_k, 0) / 1000.0
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

    private function query(?CarbonImmutable $since, ?Scenario $scenario = null, ?AiUsageKind $kind = null): Builder
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
                        "regexp_replace(ai_usage_records.model, '".BaseModelName::SQL_REGEX."', '') = ai_model_prices.model"
                    );
            });
        }

        return $builder
            ->when($since instanceof CarbonImmutable, fn (Builder $builder) => $builder->where('ai_usage_records.created_at', '>=', $since))
            ->when($kind instanceof AiUsageKind, fn (Builder $builder) => $builder->where('ai_usage_records.kind', $kind->value));
    }
}
