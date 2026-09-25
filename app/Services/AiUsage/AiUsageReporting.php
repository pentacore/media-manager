<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Enums\AiUsageKind;
use App\Enums\FreePoolOverflowBehavior;
use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiModelRateLimit;
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

    /**
     * Postgres regex stripping a trailing date-version suffix (e.g.
     * "-2025-09-23") off a recorded model id, so dated variants match
     * their catalog row. Keep in sync with the PHP-side preg_replace in
     * invocationDetail().
     */
    private const string BASE_MODEL_REGEX = '-[0-9]{4}-[0-9]{2}-[0-9]{2}$';

    private const string TOKEN_SUM_EXPR = '
        ai_usage_records.prompt_tokens
        + ai_usage_records.completion_tokens
        + ai_usage_records.cache_read_input_tokens
        + ai_usage_records.cache_write_input_tokens
        + ai_usage_records.reasoning_tokens
    ';

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
            $totalCost = max(0.0, $totalCost - $this->freePoolDiscount($since, $kind));
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
            // Fit-or-paid pools only spend quota on requests that fit it in
            // full, so "used" must come from the same per-request replay the
            // discount uses — an aggregate sum would count billed requests.
            $usage = $pool->overflow_behavior === FreePoolOverflowBehavior::FitOrPaid
                ? $this->fitOrPaidUsageRows($pool)
                : $this->poolUsageRows($pool, $pool->period->currentPeriodStart());

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
     * Strip a trailing date-version suffix (e.g. "-2025-09-23") off a model id
     * so dated variants match their catalog row. PHP twin of BASE_MODEL_REGEX.
     */
    public static function baseModel(string $model): string
    {
        return (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model);
    }

    /**
     * Per-model consumption against configured provider rate limits, each
     * limit measured over its rolling window (the last minute/hour/day —
     * rolling, unlike the free pools' calendar resets). Token limits count
     * prompt + completion tokens only (unlike pool accounting, which also
     * counts cached read/write tokens). AiRateLimitGuard enforces the same
     * numbers when enforcement is switched on.
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

        $usageByPeriod = $this->rateLimitUsageByPeriod(
            $prices->flatMap(fn (AiModelPrice $aiModelPrice) => $aiModelPrice->rateLimits->pluck('period'))->unique(),
        );

        $rows = [];

        foreach ($prices as $price) {
            $limits = [];

            foreach ($price->rateLimits as $rateLimit) {
                $limits[] = [
                    'metric' => $rateLimit->metric->value,
                    'period' => $rateLimit->period->value,
                    'limit_value' => $rateLimit->limit_value,
                    'used' => self::rateLimitUsed(
                        $rateLimit,
                        $usageByPeriod[$rateLimit->period->value][$price->provider.'|'.$price->model] ?? null,
                    ),
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
     * Requests and prompt+completion tokens recorded inside each period's
     * rolling window: one aggregate query per distinct window length, keyed
     * `period value => provider|base_model => {requests, tokens}` (dated
     * suffixes stripped like poolUsageRows). Pass a provider and base model
     * to narrow the aggregate to a single catalog row.
     *
     * @param  iterable<int, RateLimitPeriod>  $periods
     * @return array<string, Collection<string, object{requests: int|string, tokens: int|string}>>
     */
    public function rateLimitUsageByPeriod(iterable $periods, ?string $provider = null, ?string $baseModel = null): array
    {
        $usageByPeriod = [];

        foreach ($periods as $period) {
            $usageByPeriod[$period->value] = DB::table('ai_usage_records')
                ->where('created_at', '>=', $period->windowStart())
                ->whereNotNull('provider')
                ->whereNotNull('model')
                ->when($provider !== null, fn (Builder $builder): Builder => $builder->where('provider', $provider))
                ->when($baseModel !== null, fn (Builder $builder): Builder => $builder->whereRaw(
                    "regexp_replace(model, '".self::BASE_MODEL_REGEX."', '') = ?",
                    [$baseModel],
                ))
                ->selectRaw("
                    provider,
                    regexp_replace(model, '".self::BASE_MODEL_REGEX."', '') AS base_model,
                    COUNT(*) AS requests,
                    COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS tokens
                ")
                ->groupByRaw('provider, base_model')
                ->get()
                ->keyBy(fn (object $row): string => $row->provider.'|'.$row->base_model);
        }

        return $usageByPeriod;
    }

    /**
     * The "used" figure a limit is compared against, read off one aggregate
     * row from rateLimitUsageByPeriod() (null when nothing was recorded).
     */
    public static function rateLimitUsed(AiModelRateLimit $aiModelRateLimit, ?object $usage): int
    {
        return (int) ($aiModelRateLimit->metric === RateLimitMetric::Requests
            ? ($usage->requests ?? 0)
            : ($usage->tokens ?? 0));
    }

    /**
     * USD value of tokens forgiven by free-usage pools inside the window.
     * A window can span several pool resets (a 30d view over a daily pool
     * crosses ~30 boundaries), so forgiveness is bounded per (pool, period
     * bucket), measured against the bucket's FULL period usage — including
     * usage before $since — so a window that opens mid-period doesn't
     * re-grant a cap the period already spent.
     *
     * How a bucket's cap is applied depends on the pool's overflow behavior:
     *
     * - fit_or_paid replays requests chronologically; each draws from the
     *   quota only when it fits in full, otherwise it is billed whole.
     * - split forgives min(bucket usage, pool cap) and converts it to USD
     *   proportionally across member models by their share of the bucket's
     *   usage — pools group same-family models, so exact chronological
     *   allocation isn't worth the extra SQL.
     *
     * A $kind narrows only which rows' forgiveness is counted: quota
     * consumption is still measured against every kind sharing the pool.
     */
    private function freePoolDiscount(?CarbonImmutable $since, ?AiUsageKind $kind = null): float
    {
        $pools = AiFreeUsagePool::query()->with('prices')->get();

        $discount = 0.0;

        foreach ($pools as $pool) {
            $rates = [];

            foreach ($pool->prices as $price) {
                $rates[$price->provider.'|'.$price->model] = [
                    'input' => (float) $price->input_per_mtok,
                    'output' => (float) $price->output_per_mtok,
                    'cache_read' => (float) $price->cache_read_per_mtok,
                    'cache_write' => (float) $price->cache_write_per_mtok,
                ];
            }

            if ($rates === []) {
                continue;
            }

            if ($pool->overflow_behavior === FreePoolOverflowBehavior::FitOrPaid) {
                $discount += $this->fitOrPaidDiscount($pool, $rates, $since, $kind);

                continue;
            }

            $buckets = $this->poolUsageRows($pool, $since, bucketed: true, kind: $kind)
                ->groupBy('bucket');

            foreach ($buckets as $bucket) {
                $bucketInput = (int) $bucket->sum('used_input');
                $bucketOutput = (int) $bucket->sum('used_output');

                // Ratios come from the bucket's full period usage; the USD
                // conversion below only counts the window's share of it.
                if ($pool->unified) {
                    $bucketTotal = $bucketInput + $bucketOutput;
                    $forgivenRatio = $bucketTotal > 0
                        ? min($bucketTotal, (int) ($pool->free_total_tokens ?? 0)) / $bucketTotal
                        : 0.0;

                    foreach ($bucket as $row) {
                        $rate = $rates[$row->provider.'|'.$row->base_model];
                        $discount += ($this->windowInputValue($row, $rate) + (int) $row->window_output * $rate['output'])
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
                    $discount += $this->windowInputValue($row, $rate) * $inputRatio / 1_000_000.0;
                    $discount += (int) $row->window_output * $rate['output'] * $outputRatio / 1_000_000.0;
                }
            }
        }

        return $discount;
    }

    /**
     * USD value of a bucketed row's window-side input tokens, each class at
     * its own rate (uncached input vs cache read/write) — mirrors how gross
     * cost prices them, so forgiveness never exceeds what was billed.
     *
     * @param  object{window_input: int|string, window_cache_read: int|string, window_cache_write: int|string}  $row
     * @param  array{input: float, output: float, cache_read: float, cache_write: float}  $rate
     */
    private function windowInputValue(object $row, array $rate): float
    {
        return (int) $row->window_input * $rate['input']
            + (int) $row->window_cache_read * $rate['cache_read']
            + (int) $row->window_cache_write * $rate['cache_write'];
    }

    /**
     * Input/output token sums for a pool's member models since $since (null
     * = no lower bound), grouped by (provider, base model[, period bucket]).
     * Base model = recorded model id with any trailing -YYYY-MM-DD suffix
     * stripped, so dated variants match their catalog row. Input-side sums
     * (used_input) include cached read/write tokens — cache hits still
     * consume provider free-tier quota.
     *
     * Bucketed rows fetch from the START of the period containing $since —
     * not $since itself — so per-bucket caps are measured against the full
     * period's usage. used_* covers the whole bucket; window_* only the
     * rows at or after $since (and of $kind, when given), split per token
     * class so forgiveness can be valued at each class's own rate.
     *
     * @return Collection<int, object{provider: string, base_model: string, bucket?: string, used_input: int|string, used_output: int|string, window_input?: int|string, window_cache_read?: int|string, window_cache_write?: int|string, window_output?: int|string}>
     */
    private function poolUsageRows(AiFreeUsagePool $aiFreeUsagePool, ?CarbonImmutable $since, bool $bucketed = false, ?AiUsageKind $kind = null): Collection
    {
        $memberProviders = $aiFreeUsagePool->prices
            ->map(fn (AiModelPrice $aiModelPrice): string => $aiModelPrice->provider)
            ->unique()
            ->all();

        $memberKeys = $aiFreeUsagePool->prices
            ->map(fn (AiModelPrice $aiModelPrice): string => $aiModelPrice->provider.'|'.$aiModelPrice->model)
            ->all();

        if ($memberKeys === []) {
            return new Collection;
        }

        $selects = ["
            ai_usage_records.provider,
            regexp_replace(ai_usage_records.model, '".self::BASE_MODEL_REGEX."', '') AS base_model,
            COALESCE(SUM(ai_usage_records.prompt_tokens + ai_usage_records.cache_read_input_tokens + ai_usage_records.cache_write_input_tokens), 0) AS used_input,
            COALESCE(SUM(ai_usage_records.completion_tokens), 0) AS used_output
        "];
        $bindings = [];

        $groupBy = ['provider', 'base_model'];

        if ($bucketed) {
            $selects[] = sprintf("date_trunc('%s', ai_usage_records.created_at) AS bucket", $aiFreeUsagePool->period->sqlDateTrunc());
            $groupBy[] = 'bucket';

            $windowConditions = [];
            $windowBindings = [];

            if ($since instanceof CarbonImmutable) {
                $windowConditions[] = 'ai_usage_records.created_at >= ?';
                $windowBindings[] = $since;
            }

            if ($kind instanceof AiUsageKind) {
                $windowConditions[] = 'ai_usage_records.kind = ?';
                $windowBindings[] = $kind->value;
            }

            $windowFilter = $windowConditions === []
                ? ''
                : sprintf(' FILTER (WHERE %s)', implode(' AND ', $windowConditions));

            $selects[] = sprintf('
                COALESCE(SUM(ai_usage_records.prompt_tokens)%1$s, 0) AS window_input,
                COALESCE(SUM(ai_usage_records.cache_read_input_tokens)%1$s, 0) AS window_cache_read,
                COALESCE(SUM(ai_usage_records.cache_write_input_tokens)%1$s, 0) AS window_cache_write,
                COALESCE(SUM(ai_usage_records.completion_tokens)%1$s, 0) AS window_output
            ', $windowFilter);
            $bindings = [...$windowBindings, ...$windowBindings, ...$windowBindings, ...$windowBindings];
        }

        $fetchStart = $bucketed && $since instanceof CarbonImmutable
            ? $aiFreeUsagePool->period->periodStartAt($since)
            : $since;

        return DB::table('ai_usage_records')
            ->when($fetchStart instanceof CarbonImmutable, fn (Builder $builder) => $builder->where('ai_usage_records.created_at', '>=', $fetchStart))
            ->whereIn('ai_usage_records.provider', $memberProviders)
            ->whereNotNull('ai_usage_records.model')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->groupByRaw(implode(', ', $groupBy))
            ->get()
            ->filter(fn (object $row): bool => in_array($row->provider.'|'.$row->base_model, $memberKeys, true))
            ->values();
    }

    /**
     * USD forgiven by a fit-or-paid pool inside the window: requests replay
     * chronologically from the start of the period containing $since (so
     * quota spent before the window isn't re-granted), but only fitting
     * requests at or after $since convert to USD. Uncapped split dimensions
     * hold no free budget, so their tokens stay billed even on fitting
     * requests — matching the split branch's treatment of null caps. A $kind
     * limits which fitting requests convert to USD, not the replay itself.
     *
     * @param  array<string, array{input: float, output: float}>  $rates
     */
    private function fitOrPaidDiscount(AiFreeUsagePool $aiFreeUsagePool, array $rates, ?CarbonImmutable $since, ?AiUsageKind $kind = null): float
    {
        $discount = 0.0;

        foreach ($this->replayFitOrPaid($aiFreeUsagePool, $since) as $row) {
            if (! $row->fits) {
                continue;
            }

            if ($kind instanceof AiUsageKind && $row->kind !== $kind->value) {
                continue;
            }

            if ($since instanceof CarbonImmutable && CarbonImmutable::parse((string) $row->created_at, 'UTC')->lessThan($since)) {
                continue;
            }

            $rate = $rates[$row->provider.'|'.$row->base_model];

            // Cached read/write tokens count as input-side usage; each class
            // is valued at its own rate, mirroring gross cost pricing.
            $inputValue = (int) $row->prompt_tokens * $rate['input']
                + (int) $row->cache_read_input_tokens * $rate['cache_read']
                + (int) $row->cache_write_input_tokens * $rate['cache_write'];

            if ($aiFreeUsagePool->unified) {
                $discount += ($inputValue + (int) $row->completion_tokens * $rate['output']) / 1_000_000.0;

                continue;
            }

            if ($aiFreeUsagePool->free_input_tokens !== null) {
                $discount += $inputValue / 1_000_000.0;
            }

            if ($aiFreeUsagePool->free_output_tokens !== null) {
                $discount += (int) $row->completion_tokens * $rate['output'] / 1_000_000.0;
            }
        }

        return $discount;
    }

    /**
     * Aggregate fitting-request usage per (provider, base model) for the
     * pool's current period — the fit-or-paid analogue of poolUsageRows()
     * for the status panel. Billed (non-fitting) requests draw nothing from
     * the pool, so they don't count as used.
     *
     * @return Collection<int, object{provider: string, base_model: string, used_input: int, used_output: int}>
     */
    private function fitOrPaidUsageRows(AiFreeUsagePool $aiFreeUsagePool): Collection
    {
        $totals = [];

        foreach ($this->replayFitOrPaid($aiFreeUsagePool, $aiFreeUsagePool->period->currentPeriodStart()) as $row) {
            if (! $row->fits) {
                continue;
            }

            $key = $row->provider.'|'.$row->base_model;
            $totals[$key] ??= (object) [
                'provider' => $row->provider,
                'base_model' => $row->base_model,
                'used_input' => 0,
                'used_output' => 0,
            ];
            $totals[$key]->used_input += (int) $row->prompt_tokens + (int) $row->cache_read_input_tokens + (int) $row->cache_write_input_tokens;
            $totals[$key]->used_output += (int) $row->completion_tokens;
        }

        return new Collection(array_values($totals));
    }

    /**
     * Chronological replay of a pool's requests, marking each row with
     * whether it fit the remaining free quota of its period bucket at its
     * turn. A fitting request consumes quota; a non-fitting one leaves the
     * quota untouched, so a later smaller request can still draw from it.
     * Unified pools require input + output to fit the shared budget
     * together; split pools require every capped dimension to fit its own.
     * Input includes cached read/write tokens.
     *
     * @return Collection<int, object{provider: string, base_model: string, kind: string, prompt_tokens: int|string, completion_tokens: int|string, cache_read_input_tokens: int|string, cache_write_input_tokens: int|string, created_at: string, fits: bool}>
     */
    private function replayFitOrPaid(AiFreeUsagePool $aiFreeUsagePool, ?CarbonImmutable $since): Collection
    {
        $remaining = [];

        return $this->poolUsageRecords($aiFreeUsagePool, $since)->map(function (object $row) use ($aiFreeUsagePool, &$remaining): object {
            $bucket = $aiFreeUsagePool->period
                ->periodStartAt(CarbonImmutable::parse((string) $row->created_at, 'UTC'))
                ->toIso8601String();

            $remaining[$bucket] ??= [
                'input' => $aiFreeUsagePool->free_input_tokens,
                'output' => $aiFreeUsagePool->free_output_tokens,
                'total' => (int) ($aiFreeUsagePool->free_total_tokens ?? 0),
            ];

            // Cache hits still consume provider free-tier quota, so cached
            // read/write tokens count toward the input side of the fit check.
            $input = (int) $row->prompt_tokens + (int) $row->cache_read_input_tokens + (int) $row->cache_write_input_tokens;
            $output = (int) $row->completion_tokens;

            if ($aiFreeUsagePool->unified) {
                $row->fits = $remaining[$bucket]['total'] >= $input + $output;

                if ($row->fits) {
                    $remaining[$bucket]['total'] -= $input + $output;
                }

                return $row;
            }

            $row->fits = ($remaining[$bucket]['input'] === null || $remaining[$bucket]['input'] >= $input)
                && ($remaining[$bucket]['output'] === null || $remaining[$bucket]['output'] >= $output);

            if ($row->fits) {
                $remaining[$bucket]['input'] = $remaining[$bucket]['input'] === null ? null : $remaining[$bucket]['input'] - $input;
                $remaining[$bucket]['output'] = $remaining[$bucket]['output'] === null ? null : $remaining[$bucket]['output'] - $output;
            }

            return $row;
        });
    }

    /**
     * Individual usage rows for a pool's member models in chronological
     * order, fetched from the start of the period containing $since (null =
     * all history) so replays always see the bucket's full usage.
     *
     * @return Collection<int, object{provider: string, base_model: string, kind: string, prompt_tokens: int|string, completion_tokens: int|string, cache_read_input_tokens: int|string, cache_write_input_tokens: int|string, created_at: string}>
     */
    private function poolUsageRecords(AiFreeUsagePool $aiFreeUsagePool, ?CarbonImmutable $since): Collection
    {
        $memberProviders = $aiFreeUsagePool->prices
            ->map(fn (AiModelPrice $aiModelPrice): string => $aiModelPrice->provider)
            ->unique()
            ->all();

        $memberKeys = $aiFreeUsagePool->prices
            ->map(fn (AiModelPrice $aiModelPrice): string => $aiModelPrice->provider.'|'.$aiModelPrice->model)
            ->all();

        if ($memberKeys === []) {
            return new Collection;
        }

        $fetchStart = $since instanceof CarbonImmutable
            ? $aiFreeUsagePool->period->periodStartAt($since)
            : null;

        return DB::table('ai_usage_records')
            ->when($fetchStart instanceof CarbonImmutable, fn (Builder $builder) => $builder->where('ai_usage_records.created_at', '>=', $fetchStart))
            ->whereIn('ai_usage_records.provider', $memberProviders)
            ->whereNotNull('ai_usage_records.model')
            ->selectRaw("
                ai_usage_records.provider,
                regexp_replace(ai_usage_records.model, '".self::BASE_MODEL_REGEX."', '') AS base_model,
                ai_usage_records.kind,
                ai_usage_records.prompt_tokens,
                ai_usage_records.completion_tokens,
                ai_usage_records.cache_read_input_tokens,
                ai_usage_records.cache_write_input_tokens,
                ai_usage_records.created_at
            ")
            ->oldest('ai_usage_records.created_at')
            ->orderBy('ai_usage_records.id')
            ->get()
            ->filter(fn (object $row): bool => in_array($row->provider.'|'.$row->base_model, $memberKeys, true))
            ->values();
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
     * @return Collection<int, object>
     */
    public function recentInvocations(?CarbonImmutable $since, ?Scenario $scenario = null, int $limit = 50, ?AiUsageKind $kind = null): Collection
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
    public function invocationDetail(AiUsageRecord $aiUsageRecord, ?Scenario $scenario = null): array
    {
        $aiUsageRecord->loadMissing('user');

        $catalog = AiModelPrice::query()
            ->where('provider', $aiUsageRecord->provider)
            ->where('model', self::baseModel((string) $aiUsageRecord->model))
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
                        "regexp_replace(ai_usage_records.model, '".self::BASE_MODEL_REGEX."', '') = ai_model_prices.model"
                    );
            });
        }

        return $builder
            ->when($since instanceof CarbonImmutable, fn (Builder $builder) => $builder->where('ai_usage_records.created_at', '>=', $since))
            ->when($kind instanceof AiUsageKind, fn (Builder $builder) => $builder->where('ai_usage_records.kind', $kind->value));
    }
}
