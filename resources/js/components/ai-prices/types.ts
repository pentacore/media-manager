export type PricingSourceKey =
    | 'seed'
    | 'models_dev'
    | 'first_party'
    | 'manual'
    | 'legacy'
    | 'openrouter'
    | 'litellm'
    | 'xai_api'
    | 'feed_consensus';

export type CatalogPriceColumn =
    | 'input_per_mtok'
    | 'output_per_mtok'
    | 'cache_read_per_mtok'
    | 'cache_write_per_mtok'
    | 'reasoning_per_mtok'
    | 'search_unit_per_k'
    | 'batch_input_per_mtok'
    | 'batch_output_per_mtok'
    | 'batch_cache_read_per_mtok'
    | 'batch_cache_write_per_mtok'
    | 'batch_reasoning_per_mtok'
    | 'batch_search_unit_per_k';

export interface CatalogModelOption {
    model: string;
    source: PricingSourceKey;
    source_url: string | null;
    tiered: boolean;
    prices: Record<CatalogPriceColumn, string | null>;
}

export interface RateLimitDraft {
    metric: 'requests' | 'tokens';
    period: 'minute' | 'hour' | 'day';
    limit_value: number | undefined;
}
