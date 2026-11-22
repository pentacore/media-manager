import type {
    FreePoolOverflowBehavior,
    FreeUsagePeriod,
    PricingSource,
    RateLimitMetric,
    RateLimitPeriod,
} from '@/typefinder';

export type PricingSourceKey = PricingSource;

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
    metric: RateLimitMetric;
    period: RateLimitPeriod;
    limit_value: number | undefined;
}

export interface PriceRow {
    id: number;
    provider: string;
    model: string;
    input_per_mtok: string;
    output_per_mtok: string;
    cache_read_per_mtok: string;
    cache_write_per_mtok: string;
    reasoning_per_mtok: string;
    batch_input_per_mtok: string | null;
    batch_output_per_mtok: string | null;
    batch_cache_read_per_mtok: string | null;
    batch_cache_write_per_mtok: string | null;
    batch_reasoning_per_mtok: string | null;
    search_unit_per_k: string;
    batch_search_unit_per_k: string | null;
    free_usage_pool_id: number | null;
    pricing_source: PricingSourceKey | null;
    pricing_source_url: string | null;
    pricing_source_updated_at: string | null;
    pricing_synced_at: string | null;
    pricing_verified_at: string | null;
    is_price_locked: boolean;
    supports_reasoning: boolean | null;
    reasoning_levels: string[] | null;
    automatic_updates_enabled: boolean;
    rate_limits: {
        id: number;
        metric: RateLimitMetric;
        period: RateLimitPeriod;
        limit_value: number;
    }[];
}

export interface PoolRow {
    id: number;
    name: string;
    period: FreeUsagePeriod;
    unified: boolean;
    free_input_tokens: number | null;
    free_output_tokens: number | null;
    free_total_tokens: number | null;
    overflow_behavior: FreePoolOverflowBehavior;
    documentation_url: string | null;
    prices_count: number;
}

export type RateField =
    | 'input_per_mtok'
    | 'output_per_mtok'
    | 'cache_read_per_mtok'
    | 'cache_write_per_mtok'
    | 'reasoning_per_mtok';
