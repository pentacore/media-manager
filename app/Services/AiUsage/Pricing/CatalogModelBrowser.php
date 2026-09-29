<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\CatalogModelOption;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Read side of the admin "add from catalog" picker.
 *
 * Loads one provider's slice of {@see PricingCatalog} (the same adapted,
 * reconciled candidates a refresh would write), caches it per provider as
 * plain arrays, and filters out models that already have a price row. The
 * existing-row filter runs after the cache read so a model disappears the
 * moment it is added. Failures are never cached.
 */
final readonly class CatalogModelBrowser
{
    public const int CACHE_TTL_SECONDS = 900;

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

        foreach ($this->entries($canonical) as $entry) {
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
     * The writer-ready candidate for a model the admin may add, or null when
     * the catalog does not list it or it already has a price row.
     *
     * @throws CatalogUnavailableException
     */
    public function addableCandidate(string $provider, string $model): ?ModelPriceCandidate
    {
        $canonical = RefreshScope::canonicalProvider($provider);

        if ($canonical === null || isset($this->existingModels($canonical)[$model])) {
            return null;
        }

        $entry = $this->entry($canonical, $model);

        if ($entry === null) {
            return null;
        }

        $fields = [];

        foreach ($entry['prices'] as $column => $value) {
            $fields[$column] = $value === null ? CandidatePriceField::missing() : CandidatePriceField::of($value);
        }

        return new ModelPriceCandidate(
            provider: $canonical,
            model: $entry['model'],
            fields: $fields,
            source: PricingSource::from($entry['source']),
            sourceUrl: $entry['source_url'],
            sourceUpdatedAt: $entry['source_updated_at'],
            tiered: $entry['tiered'],
        );
    }

    /**
     * Provenance, lock and batch attributes for an Add-form row picked from the
     * catalog, or null when any submitted standard rate differs from the
     * catalog (the admin edited it) or the catalog cannot be read.
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

        try {
            $entry = $this->entry($canonical, $model);
        } catch (CatalogUnavailableException) {
            return null;
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
     * @return array{model: string, source: string, source_url: string|null, source_updated_at: string|null, tiered: bool, prices: array<string, string|null>}|null
     *
     * @throws CatalogUnavailableException
     */
    private function entry(string $provider, string $model): ?array
    {
        foreach ($this->entries($provider) as $entry) {
            if ($entry['model'] === $model) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The provider's catalog slice as cacheable arrays, sorted by model id.
     * Cache::remember stores nothing when the fetch throws.
     *
     * @return list<array{model: string, source: string, source_url: string|null, source_updated_at: string|null, tiered: bool, prices: array<string, string|null>}>
     *
     * @throws CatalogUnavailableException
     */
    private function entries(string $provider): array
    {
        return Cache::remember(
            sprintf('ai-pricing:catalog:%s', $provider),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->fetchEntries($provider),
        );
    }

    /**
     * @return list<array{model: string, source: string, source_url: string|null, source_updated_at: string|null, tiered: bool, prices: array<string, string|null>}>
     *
     * @throws CatalogUnavailableException
     */
    private function fetchEntries(string $provider): array
    {
        $pricingCatalogResult = $this->pricingCatalog->fetch(RefreshScope::forProviders([$provider]));

        if ($pricingCatalogResult->unavailable) {
            throw CatalogUnavailableException::fromResult($pricingCatalogResult);
        }

        $entries = [];

        foreach ($pricingCatalogResult->providers[$provider]->candidates ?? [] as $candidate) {
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

        return $entries;
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
