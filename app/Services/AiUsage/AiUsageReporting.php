<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

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

        return [
            'total_invocations' => (int) ($row->total_invocations ?? 0),
            'total_tool_calls' => (int) ($row->total_tool_calls ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'total_cost' => (string) ($row->total_cost ?? '0'),
        ];
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
            ->get();
    }

    /**
     * Returns SQL expression + bindings for the cost-per-row computation.
     *
     * - Without scenario: joins ai_model_prices and uses table rates (cost is
     *   zero for unpriced models).
     * - With scenario: uses the scenario's flat rates uniformly across all
     *   rows, ignoring ai_model_prices entirely.
     *
     * @return array{0: string, 1: array<int, float>}
     */
    private function costExpression(?Scenario $scenario): array
    {
        if (! $scenario instanceof Scenario) {
            return [
                '
                    COALESCE(
                        (
                            ai_usage_records.prompt_tokens * COALESCE(ai_model_prices.input_per_mtok, 0)
                            + ai_usage_records.completion_tokens * COALESCE(ai_model_prices.output_per_mtok, 0)
                            + ai_usage_records.cache_read_input_tokens * COALESCE(ai_model_prices.cache_read_per_mtok, 0)
                            + ai_usage_records.cache_write_input_tokens * COALESCE(ai_model_prices.cache_write_per_mtok, 0)
                            + ai_usage_records.reasoning_tokens * COALESCE(ai_model_prices.reasoning_per_mtok, 0)
                        ) / 1000000.0,
                        0
                    )
                ',
                [],
            ];
        }

        return [
            '
                (
                    ai_usage_records.prompt_tokens * ?
                    + ai_usage_records.completion_tokens * ?
                    + ai_usage_records.cache_read_input_tokens * ?
                    + ai_usage_records.cache_write_input_tokens * ?
                    + ai_usage_records.reasoning_tokens * ?
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
        if (! $scenario instanceof Scenario) {
            $builder->leftJoin('ai_model_prices', function ($join): void {
                $join->on('ai_usage_records.provider', '=', 'ai_model_prices.provider')
                    ->on('ai_usage_records.model', '=', 'ai_model_prices.model');
            });
        }

        return $builder->where('ai_usage_records.created_at', '>=', $since);
    }
}
