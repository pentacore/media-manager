<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import {
    EditPoolDialog,
    EditPriceDialog,
    FreeUsagePoolsTable,
    PriceStatCards,
    PricesHeader,
    PricesTable,
} from '@/components/ai-prices';
import type { PoolRow, PriceRow } from '@/components/ai-prices';
import { useAiPriceRefresh } from '@/composables/useAiPriceRefresh';
import { dashboard } from '@/routes';

const props = defineProps<{
    prices: PriceRow[];
    pools: PoolRow[];
    refresh_running: boolean;
    rate_limit_metrics: Array<{ value: string; label: string }>;
    rate_limit_periods: Array<{ value: string; label: string }>;
    catalog_providers: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'AI Prices', href: AiModelPriceController.index.url() },
        ],
    },
});

const { refreshing, refreshPrices, subscribe } = useAiPriceRefresh(
    props.refresh_running,
);

onMounted(subscribe);

// Copies of the row and pool being edited; null closes their dialog.
const editing = ref<PriceRow | null>(null);
const editingPool = ref<PoolRow | null>(null);

function startEdit(price: PriceRow): void {
    editing.value = { ...price };
}

function startPoolEdit(pool: PoolRow): void {
    editingPool.value = { ...pool };
}
</script>

<template>
    <Head title="AI Model Prices" />

    <div class="flex flex-col gap-4 p-5">
        <PricesHeader
            :refreshing="refreshing"
            :catalog-providers="catalog_providers"
            :pools="pools"
            :rate-limit-metrics="rate_limit_metrics"
            :rate-limit-periods="rate_limit_periods"
            @refresh="refreshPrices"
        />

        <PriceStatCards :prices="prices" />

        <FreeUsagePoolsTable :pools="pools" @edit="startPoolEdit" />

        <EditPoolDialog v-model:pool="editingPool" />

        <PricesTable
            :prices="prices"
            :pools="pools"
            :rate-limit-metrics="rate_limit_metrics"
            :rate-limit-periods="rate_limit_periods"
            @edit="startEdit"
        />

        <EditPriceDialog
            v-model:price="editing"
            :pools="pools"
            :rate-limit-metrics="rate_limit_metrics"
            :rate-limit-periods="rate_limit_periods"
        />
    </div>
</template>
