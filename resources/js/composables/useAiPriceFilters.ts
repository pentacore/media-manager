import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { ALL_PROVIDERS } from '@/components/ai-prices/priceTable';
import type { PriceRow } from '@/components/ai-prices/types';
import { useBulkSelection } from '@/composables/useBulkSelection';

export type UseAiPriceFiltersReturn = {
    providerFilter: Ref<string>;
    modelSearch: Ref<string>;
    providerOptions: ComputedRef<string[]>;
    visiblePrices: ComputedRef<PriceRow[]>;
    selectedIds: ComputedRef<number[]>;
    selectedPrices: ComputedRef<PriceRow[]>;
    selectAllState: ComputedRef<boolean | 'indeterminate'>;
    toggleAllVisible: () => void;
    toggleRow: (id: number, selected: boolean | 'indeterminate') => void;
    clearSelection: () => void;
};

/**
 * Provider and model-name filters for the AI prices table, plus the bulk
 * selection (useBulkSelection), which never holds a row the filters hide:
 * narrowing the filters, or a reload dropping rows, prunes it with retain()
 * rather than clearing it. Imports the table helpers by file path, not
 * through the `ai-prices` barrel, because the barrel's PricesTable imports
 * this composable.
 */
export function useAiPriceFilters(
    prices: () => PriceRow[],
): UseAiPriceFiltersReturn {
    const providerFilter = ref(ALL_PROVIDERS);
    const modelSearch = ref('');

    const providerOptions = computed(() =>
        [...new Set(prices().map((price) => price.provider))].sort(),
    );

    const visiblePrices = computed(() => {
        const needle = modelSearch.value.trim().toLowerCase();

        return prices().filter(
            (price) =>
                (providerFilter.value === ALL_PROVIDERS ||
                    price.provider === providerFilter.value) &&
                (needle === '' || price.model.toLowerCase().includes(needle)),
        );
    });

    // No resetOn: a filter change prunes the selection instead of clearing it.
    const selection = useBulkSelection<number>();

    // A bulk action must never reach a row the admin can't see, so narrowing the
    // filter (or a reload dropping rows) prunes the selection to visible rows.
    watch(visiblePrices, (visible) => {
        selection.retain(visible.map((price) => price.id));
    });

    const selectedPrices = computed(() =>
        visiblePrices.value.filter((price) => selection.isSelected(price.id)),
    );

    const selectAllState = computed<boolean | 'indeterminate'>(() => {
        if (selectedPrices.value.length === 0) {
            return false;
        }

        return selectedPrices.value.length === visiblePrices.value.length
            ? true
            : 'indeterminate';
    });

    function toggleAllVisible(): void {
        selection.setAll(
            visiblePrices.value.map((price) => price.id),
            selectAllState.value !== true,
        );
    }

    function toggleRow(id: number, selected: boolean | 'indeterminate'): void {
        selection.toggle(id, selected === true);
    }

    return {
        providerFilter,
        modelSearch,
        providerOptions,
        visiblePrices,
        selectedIds: selection.ids,
        selectedPrices,
        selectAllState,
        toggleAllVisible,
        toggleRow,
        clearSelection: selection.clear,
    };
}
