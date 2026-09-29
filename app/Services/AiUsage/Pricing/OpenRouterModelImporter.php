<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-driven import of OpenRouter models into the price catalog, so they can
 * be picked for any AI setting. This is a manual write, like adding a price by
 * hand: it deliberately bypasses the auto-create gate that keeps automatic
 * refreshes from importing OpenRouter's whole resold catalog. Imported rows
 * stay unlocked so the regular refresh keeps their prices current, and an
 * existing row is never touched.
 */
final readonly class OpenRouterModelImporter
{
    private const string PROVIDER = 'openrouter';

    private const string CACHE_KEY = 'ai-pricing:openrouter-models';

    public function __construct(
        private OpenRouterPricingClient $openRouterPricingClient,
        private OpenRouterPricingAdapter $openRouterPricingAdapter,
    ) {}

    /**
     * Text models OpenRouter currently offers with valid pricing.
     *
     * @return list<array{id: string, name: string, context_length: int|null, input_per_mtok: string|null, output_per_mtok: string|null, added: bool}>
     *
     * @throws PricingTransportException
     */
    public function available(): array
    {
        $models = $this->models();
        $details = [];

        foreach ($models as $model) {
            if (is_array($model) && is_string($model['id'] ?? null)) {
                $details[$model['id']] = $model;
            }
        }

        $added = AiModelPrice::query()->where('provider', self::PROVIDER)->pluck('model')->all();

        return array_map(fn (ModelPriceCandidate $modelPriceCandidate): array => [
            'id' => $modelPriceCandidate->model,
            'name' => (string) ($details[$modelPriceCandidate->model]['name'] ?? $modelPriceCandidate->model),
            'context_length' => is_int($details[$modelPriceCandidate->model]['context_length'] ?? null) ? $details[$modelPriceCandidate->model]['context_length'] : null,
            'input_per_mtok' => $modelPriceCandidate->fields['input_per_mtok']->value ?? null,
            'output_per_mtok' => $modelPriceCandidate->fields['output_per_mtok']->value ?? null,
            'added' => in_array($modelPriceCandidate->model, $added, true),
        ], $this->candidates($models));
    }

    /**
     * Create catalog rows for the given OpenRouter model ids. Ids OpenRouter
     * does not offer (or offers without valid text pricing) are skipped, as
     * is every model that already has a row.
     *
     * @param  list<string>  $modelIds
     *
     * @throws PricingTransportException
     */
    public function import(array $modelIds): int
    {
        $created = 0;

        foreach ($this->candidates($this->models()) as $modelPriceCandidate) {
            if (! in_array($modelPriceCandidate->model, $modelIds, true)) {
                continue;
            }

            $aiModelPrice = AiModelPrice::query()->firstOrCreate(
                ['provider' => self::PROVIDER, 'model' => $modelPriceCandidate->model],
                [
                    ...$this->suppliedPrices($modelPriceCandidate),
                    'pricing_source' => $modelPriceCandidate->source,
                    'pricing_source_url' => $modelPriceCandidate->sourceUrl,
                    'pricing_synced_at' => now(),
                    'is_price_locked' => false,
                ],
            );

            if ($aiModelPrice->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return list<mixed>
     *
     * @throws PricingTransportException
     */
    private function models(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn (): array => $this->openRouterPricingClient->fetch());
    }

    /**
     * @param  list<mixed>  $models
     * @return list<ModelPriceCandidate>
     */
    private function candidates(array $models): array
    {
        $providerPricingResult = $this->openRouterPricingAdapter->adapt($models, RefreshScope::forProviders([self::PROVIDER]));

        if (! $providerPricingResult instanceof ProviderPricingResult) {
            return [];
        }

        return $providerPricingResult->candidates;
    }

    /**
     * @return array<string, string>
     */
    private function suppliedPrices(ModelPriceCandidate $modelPriceCandidate): array
    {
        $prices = [];

        foreach ($modelPriceCandidate->fields as $column => $candidatePriceField) {
            if ($candidatePriceField->supplied && $candidatePriceField->value !== null) {
                $prices[$column] = $candidatePriceField->value;
            }
        }

        return $prices;
    }
}
