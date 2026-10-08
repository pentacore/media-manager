<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Models\AiModelPrice;
use Illuminate\Support\Facades\Cache;

/**
 * Per-pool headroom for tier selection. FreePoolAccounting::status() replays
 * fit-or-paid pools request by request, so the figures are cached for 60
 * seconds under a key holding the UTC date (every pool period starts on a
 * UTC day boundary, so a reset never serves yesterday's figures), memoised
 * for the request or job (scoped binding) and flushed whenever a pool or
 * price row changes. Usage writes do not flush: the tiers' thresholds are
 * the headroom that covers the cache window.
 */
class PoolHeadroom
{
    private const int CACHE_SECONDS = 60;

    /** @var array<int, PoolHeadroomFigures|null>|null */
    private ?array $figures = null;

    public function __construct(private readonly FreePoolAccounting $freePoolAccounting) {}

    public static function cacheKey(): string
    {
        return sprintf('ai:pool-headroom:%s', now('UTC')->format('Y-m-d'));
    }

    /**
     * Headroom of the pool the model's AI Prices row belongs to, or null when
     * it has no pool (or the pool caps nothing).
     */
    public function forModel(string $provider, string $model): ?PoolHeadroomFigures
    {
        $poolId = AiModelPrice::query()
            ->where('provider', $provider)
            ->where('model', BaseModelName::of($model))
            ->value('free_usage_pool_id');

        return $poolId === null ? null : $this->forPool((int) $poolId);
    }

    public function forPool(int $poolId): ?PoolHeadroomFigures
    {
        return $this->all()[$poolId] ?? null;
    }

    public function flush(): void
    {
        $this->figures = null;
        Cache::forget(self::cacheKey());
    }

    /**
     * @return array<int, PoolHeadroomFigures|null>
     */
    private function all(): array
    {
        if ($this->figures !== null) {
            return $this->figures;
        }

        /** @var array<int, array{name: string, percent_left: float, tokens_left: int}|null> $cached */
        $cached = Cache::remember(self::cacheKey(), self::CACHE_SECONDS, function (): array {
            $figures = [];

            foreach ($this->freePoolAccounting->status() as $status) {
                $figures[$status['id']] = PoolHeadroomFigures::fromStatus($status)?->toArray();
            }

            return $figures;
        });

        return $this->figures = array_map(
            static fn (?array $row): ?PoolHeadroomFigures => $row === null ? null : PoolHeadroomFigures::fromArray($row),
            $cached,
        );
    }
}
