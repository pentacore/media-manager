import type { ComputedRef, Ref, WatchSource } from 'vue';
import { computed, ref, watch } from 'vue';

export type BulkId = number | string;

export type UseBulkSelectionReturn<TId extends BulkId> = {
    ids: ComputedRef<TId[]>;
    count: ComputedRef<number>;
    isSelected: (id: TId) => boolean;
    toggle: (id: TId, selected: boolean) => void;
    setAll: (pageIds: TId[], selected: boolean) => void;
    allSelected: (pageIds: TId[]) => boolean;
    clear: () => void;
    retain: (availableIds: TId[]) => void;
};

/**
 * Checkbox selection for one bulk-action surface. The selection belongs to
 * what is on screen: it clears when any `resetOn` source changes (filters,
 * search, tab, page number) and `retain()` drops ids that vanished after a
 * reload or poll, so a bulk request never names a row the user can no longer
 * see. Leaving the page unmounts it and discards the selection.
 */
export function useBulkSelection<TId extends BulkId>(
    resetOn: WatchSource[] = [],
): UseBulkSelectionReturn<TId> {
    const selected = ref([]) as Ref<TId[]>;

    const ids = computed<TId[]>(() => [...selected.value]);
    const count = computed(() => selected.value.length);

    function isSelected(id: TId): boolean {
        return selected.value.includes(id);
    }

    function toggle(id: TId, value: boolean): void {
        if (value) {
            if (!isSelected(id)) {
                selected.value = [...selected.value, id];
            }

            return;
        }

        selected.value = selected.value.filter((current) => current !== id);
    }

    function setAll(pageIds: TId[], value: boolean): void {
        if (value) {
            selected.value = [...new Set([...selected.value, ...pageIds])];

            return;
        }

        selected.value = selected.value.filter(
            (current) => !pageIds.includes(current),
        );
    }

    function allSelected(pageIds: TId[]): boolean {
        return (
            pageIds.length > 0 &&
            pageIds.every((id) => selected.value.includes(id))
        );
    }

    function clear(): void {
        selected.value = [];
    }

    function retain(availableIds: TId[]): void {
        const available = new Set(availableIds);
        const kept = selected.value.filter((id) => available.has(id));

        if (kept.length !== selected.value.length) {
            selected.value = kept;
        }
    }

    if (resetOn.length > 0) {
        watch(resetOn, clear);
    }

    return {
        ids,
        count,
        isSelected,
        toggle,
        setAll,
        allSelected,
        clear,
        retain,
    };
}
