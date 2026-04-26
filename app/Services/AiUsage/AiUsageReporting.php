<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AiUsageReporting
{
    /**
     * Cost-per-row SQL fragment. Joins ai_usage_records with ai_model_prices
     * on (provider, model). If no price row matches, cost is 0.
     */
    private const string COST_EXPR = '
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
    ';

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
    public function totals(CarbonImmutable $since): array
    {
        $row = $this->query($since)
            ->selectRaw('
                COUNT(*) AS total_invocations,
                COALESCE(SUM(ai_usage_records.tool_calls_count), 0) AS total_tool_calls,
                COALESCE(SUM('.self::TOKEN_SUM_EXPR.'), 0) AS total_tokens,
                COALESCE(SUM('.self::COST_EXPR.'), 0) AS total_cost
            ')
            ->first();

        return [
            'total_invocations' => (int) ($row->total_invocations ?? 0),
            'total_tool_calls' => (int) ($row->total_tool_calls ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'total_cost' => (string) ($row->total_cost ?? '0'),
        ];
    }

    private const array AGGREGATABLE_COLUMNS = ['agent_class', 'model', 'provider'];

    /**
     * @return Collection<int, object{key: string|null, invocations: int, total_tokens: int, total_cost: string}>
     */
    public function aggregateBy(string $column, CarbonImmutable $since): Collection
    {
        if (! in_array($column, self::AGGREGATABLE_COLUMNS, true)) {
            throw new \InvalidArgumentException("Cannot aggregate by '{$column}'.");
        }

        return $this->query($since)
            ->selectRaw("
                ai_usage_records.{$column} AS key,
                COUNT(*) AS invocations,
                COALESCE(SUM(".self::TOKEN_SUM_EXPR.'), 0) AS total_tokens,
                COALESCE(SUM('.self::COST_EXPR.'), 0) AS total_cost
            ')
            ->groupBy('ai_usage_records.'.$column)
            ->orderByDesc('total_cost')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function recentInvocations(CarbonImmutable $since, int $limit = 50): Collection
    {
        return $this->query($since)
            ->leftJoin('users', 'ai_usage_records.user_id', '=', 'users.id')
            ->selectRaw('
                ai_usage_records.id,
                ai_usage_records.created_at,
                ai_usage_records.agent_class,
                ai_usage_records.provider,
                ai_usage_records.model,
                ai_usage_records.prompt_tokens,
                ai_usage_records.completion_tokens,
                ai_usage_records.tool_calls_count,
                ai_usage_records.conversation_id,
                ai_usage_records.status,
                users.name AS user_name,
                ('.self::TOKEN_SUM_EXPR.') AS total_tokens,
                ('.self::COST_EXPR.') AS cost
            ')
            ->latest('ai_usage_records.created_at')
            ->limit($limit)
            ->get();
    }

    private function query(CarbonImmutable $since): Builder
    {
        return DB::table('ai_usage_records')
            ->leftJoin('ai_model_prices', function ($join): void {
                $join->on('ai_usage_records.provider', '=', 'ai_model_prices.provider')
                    ->on('ai_usage_records.model', '=', 'ai_model_prices.model');
            })
            ->where('ai_usage_records.created_at', '>=', $since);
    }
}
