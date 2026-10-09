<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Minus } from '@lucide/vue';
import { ref } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import { Checkbox } from '@/components/ui/checkbox';
import { useAiPriceFilters } from '@/composables/useAiPriceFilters';
import { useConfirm } from '@/composables/useConfirm';
import BulkDeletePricesDialog from './BulkDeletePricesDialog.vue';
import BulkEditPricesDialog from './BulkEditPricesDialog.vue';
import PricesBulkBar from './PricesBulkBar.vue';
import PricesTableToolbar from './PricesTableToolbar.vue';
import PriceTableRow from './PriceTableRow.vue';
import type { PoolRow, PriceRow } from './types';

/**
 * The "Configured models" card: filters, the Standard/Batch toggle, the bulk
 * bar, the rows and the two bulk dialogs, which act on the selection the
 * filters keep in view.
 */
const props = defineProps<{
    prices: PriceRow[];
    pools: PoolRow[];
    rateLimitMetrics: Array<{ value: string; label: string }>;
    rateLimitPeriods: Array<{ value: string; label: string }>;
}>();

const emit = defineEmits<{ edit: [price: PriceRow] }>();

const showBatch = ref(false);

const {
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
} = useAiPriceFilters(() => props.prices);

const showBulkEditDialog = ref(false);
const showBulkDeleteDialog = ref(false);

const { confirm } = useConfirm();

async function destroy(price: PriceRow): Promise<void> {
    const confirmed = await confirm({
        title: `Remove pricing for ${price.provider}/${price.model}?`,
        confirmLabel: 'Remove',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.visit(AiModelPriceController.destroy.url(price.id), {
        method: 'delete',
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <PricesTableToolbar
            v-model:provider="providerFilter"
            v-model:search="modelSearch"
            v-model:show-batch="showBatch"
            :provider-options="providerOptions"
        />
        <PricesBulkBar
            v-if="selectedPrices.length > 0"
            :count="selectedPrices.length"
            @edit="showBulkEditDialog = true"
            @delete="showBulkDeleteDialog = true"
            @clear="clearSelection"
        />
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]" data-prices-table>
                <thead>
                    <tr>
                        <th
                            class="w-8 border-b border-border bg-card py-2 pr-1 pl-3 text-left"
                        >
                            <Checkbox
                                :model-value="selectAllState"
                                :disabled="visiblePrices.length === 0"
                                aria-label="Select all shown models"
                                data-select-all
                                @update:model-value="toggleAllVisible"
                            >
                                <Minus
                                    v-if="selectAllState === 'indeterminate'"
                                    class="size-3.5"
                                />
                                <Check v-else class="size-3.5" />
                            </Checkbox>
                        </th>
                        <th
                            v-for="h in [
                                'Model',
                                'Provider',
                                'Input',
                                'Output',
                                'Cache R',
                                'Cache W',
                                'Reasoning',
                                'Source',
                                'Synced',
                                'Verified',
                                '',
                            ]"
                            :key="h"
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <PriceTableRow
                        v-for="price in visiblePrices"
                        :key="price.id"
                        :price="price"
                        :show-batch="showBatch"
                        :selected="selectedIds.includes(price.id)"
                        @toggle="(selected) => toggleRow(price.id, selected)"
                        @edit="emit('edit', price)"
                        @delete="destroy(price)"
                    />
                    <tr v-if="prices.length === 0">
                        <td
                            colspan="12"
                            class="px-3 py-8 text-center text-sm text-fg-subtle"
                        >
                            No models priced yet. Click "Add model price".
                        </td>
                    </tr>
                    <tr v-else-if="visiblePrices.length === 0">
                        <td
                            colspan="12"
                            class="px-3 py-8 text-center text-sm text-fg-subtle"
                        >
                            No models match the filter.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <BulkEditPricesDialog
            v-model:open="showBulkEditDialog"
            :ids="selectedPrices.map((price) => price.id)"
            :pools="pools"
            :rate-limit-metrics="rateLimitMetrics"
            :rate-limit-periods="rateLimitPeriods"
            @saved="clearSelection"
        />
        <BulkDeletePricesDialog
            v-model:open="showBulkDeleteDialog"
            :prices="selectedPrices"
            @deleted="clearSelection"
        />
    </div>
</template>
