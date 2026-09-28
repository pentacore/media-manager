<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Enums\AiUsageKind;
use App\Enums\FreePoolOverflowBehavior;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Free-usage pool accounting: how much of each pool's quota its current
 * period used (the usage page's "Free usage pools" panel) and the USD value
 * the pools forgive inside a report window (netted out of totals()).
 */
class FreePoolAccounting
{
    /**
     * Per-pool consumption of the configured free quota, each pool sized
     * to its own currently running UTC calendar period. Drives the
     * "Free usage pools" panel.
     *
     * @return array<int, array{id: int, name: string, period: string, unified: bool, documentation_url: string|null, free_input: int|null, free_output: int|null, free_total: int|null, used_input: int, used_output: int, used_total: int, models: array<int, array{provider: string, model: string, used_input: int, used_output: int}>}>
     */
    public function status(): array
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
    public function discount(?CarbonImmutable $since, ?AiUsageKind $kind = null): float
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
            regexp_replace(ai_usage_records.model, '".BaseModelName::SQL_REGEX."', '') AS base_model,
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
                regexp_replace(ai_usage_records.model, '".BaseModelName::SQL_REGEX."', '') AS base_model,
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
}
