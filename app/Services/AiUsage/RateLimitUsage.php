<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Models\AiModelPrice;
use App\Models\AiModelRateLimit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-model usage against the configured provider rate limits over rolling
 * windows — shown on the usage page and enforced by AiRateLimitGuard from
 * the same numbers.
 */
class RateLimitUsage
{
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
    public function status(): array
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

        $usageByPeriod = $this->byPeriod(
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
                    'used' => self::used(
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
    public function byPeriod(iterable $periods, ?string $provider = null, ?string $baseModel = null): array
    {
        $usageByPeriod = [];

        foreach ($periods as $period) {
            $usageByPeriod[$period->value] = DB::table('ai_usage_records')
                ->where('created_at', '>=', $period->windowStart())
                ->whereNotNull('provider')
                ->whereNotNull('model')
                ->when($provider !== null, fn (Builder $builder): Builder => $builder->where('provider', $provider))
                ->when($baseModel !== null, fn (Builder $builder): Builder => $builder->whereRaw(
                    "regexp_replace(model, '".BaseModelName::SQL_REGEX."', '') = ?",
                    [$baseModel],
                ))
                ->selectRaw("
                    provider,
                    regexp_replace(model, '".BaseModelName::SQL_REGEX."', '') AS base_model,
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
     * row from byPeriod() (null when nothing was recorded).
     */
    public static function used(AiModelRateLimit $aiModelRateLimit, ?object $usage): int
    {
        return (int) ($aiModelRateLimit->metric === RateLimitMetric::Requests
            ? ($usage->requests ?? 0)
            : ($usage->tokens ?? 0));
    }
}
