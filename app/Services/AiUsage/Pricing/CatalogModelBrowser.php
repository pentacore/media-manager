<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\CatalogModelOption;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingCatalogResult;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Read side of the admin "add from catalog" picker.
 *
 * Loads one provider's slice of {@see PricingCatalog} (the same adapted,
 * reconciled candidates a refresh would write), caches it per provider and
 * per enabled-source combination as plain arrays, and filters out models that
 * already have a price row. The existing-row filter runs after the cache read
 * so a model disappears the moment it is added. Failures are never cached.
 *
 * A cold fetch downloads every enabled feed, so it runs under a per-provider
 * lock: parallel requests for one provider share a single download.
 */
final readonly class CatalogModelBrowser
{
    public const int CACHE_TTL_SECONDS = 900;

    /**
     * How long a cold fetch may hold the per-provider lock.
     */
    public const int FETCH_LOCK_SECONDS = 120;

    /**
     * How long a request waits for another request's cold fetch to finish.
     */
    public const int FETCH_WAIT_SECONDS = 60;

    /**
     * Source statuses that do not mean a feed failed: a switched-off source,
     * or one with no credentials configured (quiet by design).
     *
     * @var list<string>
     */
    private const array QUIET_STATUSES = [
        PricingCatalogResult::STATUS_OK,
        PricingCatalogResult::STATUS_DISABLED,
        PricingTransportException::CATEGORY_NOT_CONFIGURED,
    ];

    /**
     * Standard rate columns compared when deciding whether an Add-form
     * submission still matches the catalog; a missing value counts as zero,
     * mirroring how the writer creates a row.
     *
     * @var list<string>
     */
    private const array STANDARD_COLUMNS = [
        'input_per_mtok',
        'output_per_mtok',
        'cache_read_per_mtok',
        'cache_write_per_mtok',
        'reasoning_per_mtok',
        'search_unit_per_k',
    ];

    /**
     * Batch rate columns copied from the catalog onto an Add-form row.
     *
     * @var list<string>
     */
    private const array BATCH_COLUMNS = [
        'batch_input_per_mtok',
        'batch_output_per_mtok',
        'batch_cache_read_per_mtok',
        'batch_cache_write_per_mtok',
        'batch_reasoning_per_mtok',
        'batch_search_unit_per_k',
    ];

    public function __construct(
        private PricingCatalog $pricingCatalog,
        private AiSettings $aiSettings,
    ) {}

    /**
     * Canonical providers the picker offers, sorted, minus any on the ignore list.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        /** @var array<string, string> $map */
        $map = config('mediamanager.ai.pricing.providers', []);

        $providers = [];

        foreach (array_unique(array_values($map)) as $provider) {
            if (RefreshScope::canonicalProvider($provider) === $provider) {
                $providers[] = $provider;
            }
        }

        sort($providers);

        return $providers;
    }

    /**
     * Catalog models for the provider that have no price row yet.
     *
     * @return list<CatalogModelOption>
     *
     * @throws CatalogUnavailableException
     */
    public function available(string $provider): array
    {
        $canonical = RefreshScope::canonicalProvider($provider);

        if ($canonical === null) {
            return [];
        }

        $existing = $this->existingModels($canonical);
        $options = [];

        foreach ($this->slice($canonical)['entries'] as $entry) {
            if (isset($existing[$entry['model']])) {
                continue;
            }

            $options[] = new CatalogModelOption(
                model: $entry['model'],
                source: PricingSource::from($entry['source']),
                sourceUrl: $entry['source_url'],
                tiered: $entry['tiered'],
                prices: $entry['prices'],
            );
        }

        return $options;
    }

    /**
     * Whether any enabled feed prices the provider. False when no enabled
     * source lists it, so the picker can say so instead of implying every
     * model is already added.
     *
     * @throws CatalogUnavailableException
     */
    public function covers(string $provider): bool
    {
        $canonical = RefreshScope::canonicalProvider($provider);

        return $canonical !== null && $this->slice($canonical)['covered'];
    }

    /**
     * Writer-ready candidates, keyed by model id, for the requested models the
     * admin may add: listed in the catalog and without a price row. Reads the
     * existing rows and the catalog slice once for the whole batch.
     *
     * @param  list<string>  $models
     * @return array<string, ModelPriceCandidate>
     *
     * @throws CatalogUnavailableException
     */
    public function addableCandidates(string $provider, array $models): array
    {
        $canonical = RefreshScope::canonicalProvider($provider);

        if ($canonical === null) {
            return [];
        }

        $existing = $this->existingModels($canonical);
        $entries = [];

        foreach ($this->slice($canonical)['entries'] as $entry) {
            $entries[$entry['model']] = $entry;
        }

        $candidates = [];

        foreach ($models as $model) {
            if (isset($existing[$model]) || ! isset($entries[$model])) {
                continue;
            }

            $candidates[$model] = $this->candidate($canonical, $entries[$model]);
        }

        return $candidates;
    }

    /**
     * Provenance, lock and batch attributes for an Add-form row picked from the
     * catalog, or null when any submitted standard rate differs from the
     * catalog (the admin edited it) or the catalog is not cached. Reads the
     * cache only, never the feeds: a cold cache falls back to a manual row
     * rather than making the save wait on a download.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    public function catalogAttributes(string $provider, string $model, array $validated): ?array
    {
        $canonical = RefreshScope::canonicalProvider($provider);

        if ($canonical === null) {
            return null;
        }

        $entry = null;

        foreach ($this->cachedSlice($canonical)['entries'] ?? [] as $cachedEntry) {
            if ($cachedEntry['model'] === $model) {
                $entry = $cachedEntry;

                break;
            }
        }

        if ($entry === null) {
            return null;
        }

        foreach (self::STANDARD_COLUMNS as $column) {
            $submitted = $this->columnScale($validated[$column] ?? null) ?? '0.0000';
            $catalog = $entry['prices'][$column] ?? '0.0000';

            if ($submitted !== $catalog) {
                return null;
            }
        }

        $attributes = [
            'pricing_source' => PricingSource::from($entry['source']),
            'pricing_source_url' => $entry['source_url'],
            'pricing_source_updated_at' => $entry['source_updated_at'],
            'pricing_synced_at' => CarbonImmutable::now(),
            'is_price_locked' => false,
        ];

        foreach (self::BATCH_COLUMNS as $column) {
            $attributes[$column] = $entry['prices'][$column] ?? null;
        }

        return $attributes;
    }

    /**
     * @param  array{model: string, source: string, source_url: string|null, source_updated_at: string|null, tiered: bool, prices: array<string, string|null>}  $entry
     */
    private function candidate(string $provider, array $entry): ModelPriceCandidate
    {
        $fields = [];

        foreach ($entry['prices'] as $column => $value) {
            $fields[$column] = $value === null ? CandidatePriceField::missing() : CandidatePriceField::of($value);
        }

        return new ModelPriceCandidate(
            provider: $provider,
            model: $entry['model'],
            fields: $fields,
            source: PricingSource::from($entry['source']),
            sourceUrl: $entry['source_url'],
            sourceUpdatedAt: $entry['source_updated_at'],
            tiered: $entry['tiered'],
        );
    }

    /**
     * The provider's catalog slice, fetched on a cold cache. The fetch runs
     * under a per-provider lock and re-checks the cache once it holds it, so
     * requests that queued behind a download reuse its result.
     *
     * @return array{covered: bool, entries: list<array{model: string, source: string, source_url: string|null, source_updated_at: string|null, tiered: bool, prices: array<string, string|null>}>}
     *
     * @throws CatalogUnavailableException
     */
    private function slice(string $provider): array
    {
        $cached = $this->cachedSlice($provider);

        if ($cached !== null) {
            return $cached;
        }

        try {
            return Cache::lock(sprintf('ai-pricing:catalog-fetch:%s', $provider), self::FETCH_LOCK_SECONDS)
                ->block(self::FETCH_WAIT_SECONDS, function () use ($provider): array {
                    $cached = $this->cachedSlice($provider);

                    if ($cached !== null) {
                        return $cached;
                    }

                    $slice = $this->fetchSlice($provider);
                    Cache::put($this->cacheKey($provider), $slice, self::CACHE_TTL_SECONDS);

                    return $slice;
                });
        } catch (LockTimeoutException) {
            throw new CatalogUnavailableException(__('The pricing catalog is still loading. Try again in a moment.'));
        }
    }

    /**
     * The cached slice, or null on a cold cache. Never fetches.
     *
     * @return array{covered: bool, entries: list<array{model: string, source: string, source_url: string|null, source_updated_at: string|null, tiered: bool, prices: array<string, string|null>}>}|null
     */
    private function cachedSlice(string $provider): ?array
    {
        /** @var array{covered: bool, entries: list<array{model: string, source: string, source_url: string|null, source_updated_at: string|null, tiered: bool, prices: array<string, string|null>}>}|null $cached */
        $cached = Cache::get($this->cacheKey($provider));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Keyed per provider and per enabled-source combination, so switching a
     * feed on or off in AI settings never serves a slice built from the old set.
     */
    private function cacheKey(string $provider): string
    {
        $fingerprint = md5(json_encode([
            'models_dev' => $this->aiSettings->modelsDevPricingEnabled(),
            'litellm' => $this->aiSettings->liteLlmPricingEnabled(),
            'openrouter' => $this->aiSettings->openRouterPricingEnabled(),
            'xai' => $this->aiSettings->xaiPricingEnabled(),
        ], JSON_THROW_ON_ERROR));

        return sprintf('ai-pricing:catalog:%s:%s', $provider, $fingerprint);
    }

    /**
     * Fetch the provider's slice as cacheable arrays, sorted by model id.
     *
     * A missing slice while any source failed is a partial outage: it throws,
     * so it is never cached and Retry can recover. A missing slice while every
     * source answered (or was quietly off) means no enabled feed prices the
     * provider; that stays true until the source settings change, so it is
     * cached as not covered, as is a slice with no usable candidates.
     *
     * @return array{covered: bool, entries: list<array{model: string, source: string, source_url: string|null, source_updated_at: string|null, tiered: bool, prices: array<string, string|null>}>}
     *
     * @throws CatalogUnavailableException
     */
    private function fetchSlice(string $provider): array
    {
        $pricingCatalogResult = $this->pricingCatalog->fetch(RefreshScope::forProviders([$provider]));

        if ($pricingCatalogResult->unavailable) {
            throw CatalogUnavailableException::fromResult($pricingCatalogResult);
        }

        $providerPricingResult = $pricingCatalogResult->providers[$provider] ?? null;

        if ($providerPricingResult === null && $this->anySourceFailed($provider, $pricingCatalogResult)) {
            throw CatalogUnavailableException::fromResult($pricingCatalogResult);
        }

        $entries = [];

        foreach ($providerPricingResult->candidates ?? [] as $candidate) {
            $prices = [];

            foreach (CatalogModelOption::PRICE_COLUMNS as $column) {
                $field = $candidate->fields[$column] ?? null;
                $prices[$column] = $field !== null && $field->supplied && $field->value !== null
                    ? PriceNumber::roundToColumnScale($field->value)
                    : null;
            }

            $entries[] = [
                'model' => $candidate->model,
                'source' => $candidate->source->value,
                'source_url' => $candidate->sourceUrl,
                'source_updated_at' => $candidate->sourceUpdatedAt,
                'tiered' => $candidate->tiered,
                'prices' => $prices,
            ];
        }

        usort($entries, fn (array $a, array $b): int => strcmp($a['model'], $b['model']));

        return ['covered' => $entries !== [], 'entries' => $entries];
    }

    /**
     * Whether a source that could actually have priced the requested provider
     * failed. A failed source that could never have covered this provider
     * (e.g. OpenRouter for a non-`openrouter` provider) is not an outage for
     * it: an unrelated bad credential must not turn every uncovered provider
     * into an uncached 503 on every open.
     */
    private function anySourceFailed(string $provider, PricingCatalogResult $pricingCatalogResult): bool
    {
        return array_any($pricingCatalogResult->sourceStatuses, fn (string $status, string $source): bool => $this->sourceCouldCover($source, $provider) && ! in_array($status, self::QUIET_STATUSES, true));
    }

    /**
     * Whether the given source could ever have produced candidates for the
     * given provider. models.dev and LiteLLM are reconciled across every
     * provider; OpenRouter and xAI each price only their own provider.
     */
    private function sourceCouldCover(string $source, string $provider): bool
    {
        return match ($source) {
            PricingCatalog::SOURCE_OPENROUTER => $provider === PricingCatalog::SOURCE_OPENROUTER,
            PricingCatalog::SOURCE_XAI => $provider === PricingCatalog::SOURCE_XAI,
            default => true,
        };
    }

    /**
     * @return array<string, true>
     */
    private function existingModels(string $provider): array
    {
        $existing = [];

        foreach (AiModelPrice::query()->where('provider', $provider)->pluck('model') as $model) {
            $existing[$model] = true;
        }

        return $existing;
    }

    /**
     * A submitted form value at four-decimal column scale, or null when blank or invalid.
     */
    private function columnScale(mixed $value): ?string
    {
        $normalized = PriceNumber::normalize($value);

        return $normalized === null ? null : PriceNumber::roundToColumnScale($normalized);
    }
}
