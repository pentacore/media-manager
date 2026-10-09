import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { ALL_PROVIDERS } from '@/components/ai-prices/priceTable';
import type { PriceRow } from '@/components/ai-prices/types';

export type UseAiPriceFiltersReturn = {
    providerFilter: Ref<string>;
    modelSearch: Ref<string>;
    providerOptions: ComputedRef<string[]>;
    visiblePrices: ComputedRef<PriceRow[]>;
    selectedIds: Ref<number[]>;
    selectedPrices: ComputedRef<PriceRow[]>;
    selectAllState: ComputedRef<boolean | 'indeterminate'>;
    toggleAllVisible: () => void;
    toggleRow: (id: number, selected: boolean | 'indeterminate') => void;
    clearSelection: () => void;
};

/**
 * Provider and model-name filters for the AI prices table, plus the bulk
 * selection, which never holds a row the filters hide. Imports the table
 * helpers by file path, not through the `ai-prices` barrel, because the
 * barrel's PricesTable imports this composable.
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

    const selectedIds = ref<number[]>([]);

    // A bulk action must never reach a row the admin can't see, so narrowing the
    // filter (or a reload dropping rows) prunes the selection to visible rows.
    watch(visiblePrices, (visible) => {
        const visibleIds = new Set(visible.map((price) => price.id));
        selectedIds.value = selectedIds.value.filter((id) =>
            visibleIds.has(id),
        );
    });

    const selectedPrices = computed(() =>
        visiblePrices.value.filter((price) =>
            selectedIds.value.includes(price.id),
        ),
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
        selectedIds.value =
            selectAllState.value === true
                ? []
                : visiblePrices.value.map((price) => price.id);
    }

    function toggleRow(id: number, selected: boolean | 'indeterminate'): void {
        selectedIds.value =
            selected === true
                ? [...selectedIds.value, id]
                : selectedIds.value.filter((selectedId) => selectedId !== id);
    }

    function clearSelection(): void {
        selectedIds.value = [];
    }

    return {
        providerFilter,
        modelSearch,
        providerOptions,
        visiblePrices,
        selectedIds,
        selectedPrices,
        selectAllState,
        toggleAllVisible,
        toggleRow,
        clearSelection,
    };
}
