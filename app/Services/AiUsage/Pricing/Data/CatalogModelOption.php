<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

use App\Enums\PricingSource;

/**
 * One model the admin can add from the pricing catalog: its id, the feed
 * that priced it, and every rate column at four-decimal scale (null where the
 * feed supplied no value).
 */
final readonly class CatalogModelOption
{
    /**
     * Every rate column a catalog option carries, in `ai_model_prices` names.
     *
     * @var list<string>
     */
    public const array PRICE_COLUMNS = [
        'input_per_mtok',
        'output_per_mtok',
        'cache_read_per_mtok',
        'cache_write_per_mtok',
        'reasoning_per_mtok',
        'search_unit_per_k',
        'batch_input_per_mtok',
        'batch_output_per_mtok',
        'batch_cache_read_per_mtok',
        'batch_cache_write_per_mtok',
        'batch_reasoning_per_mtok',
        'batch_search_unit_per_k',
    ];

    /**
     * @param  array<string, string|null>  $prices  Column => four-decimal rate, or null when the feed supplied none.
     */
    public function __construct(
        public string $model,
        public PricingSource $source,
        public ?string $sourceUrl,
        public bool $tiered,
        public array $prices,
    ) {}

    /**
     * @return array{model: string, source: string, source_url: string|null, tiered: bool, prices: array<string, string|null>}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'source' => $this->source->value,
            'source_url' => $this->sourceUrl,
            'tiered' => $this->tiered,
            'prices' => $this->prices,
        ];
    }
}
