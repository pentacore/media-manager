import { SOURCE_LABELS } from './pricingSources';
import type { PriceRow, RateField } from './types';

/** Provider filter value meaning "no provider filter". */
export const ALL_PROVIDERS = 'all';

const BATCH_FIELD: Record<RateField, keyof PriceRow> = {
    input_per_mtok: 'batch_input_per_mtok',
    output_per_mtok: 'batch_output_per_mtok',
    cache_read_per_mtok: 'batch_cache_read_per_mtok',
    cache_write_per_mtok: 'batch_cache_write_per_mtok',
    reasoning_per_mtok: 'batch_reasoning_per_mtok',
};

type PricingSource = NonNullable<PriceRow['pricing_source']>;

const SOURCE_VARIANTS: Record<
    PricingSource,
    'default' | 'ok' | 'warn' | 'info'
> = {
    seed: 'default',
    models_dev: 'ok',
    first_party: 'ok',
    manual: 'info',
    legacy: 'warn',
    openrouter: 'ok',
    litellm: 'ok',
    xai_api: 'ok',
    feed_consensus: 'ok',
};

export function sourceLabel(source: PriceRow['pricing_source']): string {
    return source ? SOURCE_LABELS[source] : '—';
}

export function sourceVariant(
    source: PricingSource,
): 'default' | 'ok' | 'warn' | 'info' {
    return SOURCE_VARIANTS[source];
}

/**
 * Guards the pricing-source pill link so only http(s) URLs ever become an
 * anchor. Any other scheme (javascript:, data:, etc.) or an unparseable value
 * returns null, and the template falls back to a plain, non-clickable pill.
 */
export function safeSourceHref(url: string | null): string | null {
    if (url === null) {
        return null;
    }

    try {
        const { protocol } = new URL(url);

        return protocol === 'http:' || protocol === 'https:' ? url : null;
    } catch {
        return null;
    }
}

export function fmt(rate: string | null | undefined): string {
    if (rate === null || rate === undefined) {
        return '—';
    }

    const n = parseFloat(rate);

    if (Number.isNaN(n)) {
        return '—';
    }

    return `$${n.toFixed(2)}`;
}

/**
 * The rate a table column shows: the batch rate while the Batch tier is on
 * (null when the row has none, so the caller falls back to the standard
 * rate), otherwise the standard rate.
 */
export function rateFor(
    price: PriceRow,
    field: RateField,
    showBatch: boolean,
): string | null {
    if (showBatch) {
        const value = price[BATCH_FIELD[field]];

        return value === null || value === undefined ? null : String(value);
    }

    return price[field];
}

export function hasBatch(price: PriceRow): boolean {
    return (
        price.batch_input_per_mtok !== null &&
        price.batch_input_per_mtok !== undefined
    );
}
