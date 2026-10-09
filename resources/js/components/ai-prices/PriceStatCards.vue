<script setup lang="ts">
import { ref } from 'vue';
import { StatCard } from '@/components/mm';
import { fmt } from './priceTable';
import type { PriceRow } from './types';

const props = defineProps<{
    prices: PriceRow[];
}>();

// Computed once when the page mounts, as before the split: a broadcast
// reload of the rows does not move these two cards.
const cheapest = ref(
    [...props.prices].sort(
        (a, b) =>
            parseFloat(a.input_per_mtok) +
            parseFloat(a.output_per_mtok) -
            (parseFloat(b.input_per_mtok) + parseFloat(b.output_per_mtok)),
    )[0] ?? null,
);

const priciest = ref(
    [...props.prices].sort(
        (a, b) =>
            parseFloat(b.input_per_mtok) +
            parseFloat(b.output_per_mtok) -
            (parseFloat(a.input_per_mtok) + parseFloat(a.output_per_mtok)),
    )[0] ?? null,
);
</script>

<template>
    <div class="grid gap-4 md:grid-cols-3" data-price-stats>
        <StatCard
            label="Models priced"
            :value="prices.length"
            hint="rows in this catalog"
        />
        <div
            class="flex min-h-[110px] flex-col gap-2.5 rounded-xl border border-border bg-card p-5"
        >
            <span
                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >Cheapest</span
            >
            <div v-if="cheapest">
                <div class="font-mono-tabular text-[15px] font-semibold">
                    {{ cheapest.model }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ fmt(cheapest.input_per_mtok) }} in /
                    {{ fmt(cheapest.output_per_mtok) }} out
                </div>
            </div>
            <div v-else class="text-sm text-fg-subtle">No data</div>
        </div>
        <div
            class="flex min-h-[110px] flex-col gap-2.5 rounded-xl border border-border bg-card p-5"
        >
            <span
                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >Priciest</span
            >
            <div v-if="priciest">
                <div class="font-mono-tabular text-[15px] font-semibold">
                    {{ priciest.model }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ fmt(priciest.input_per_mtok) }} in /
                    {{ fmt(priciest.output_per_mtok) }} out
                </div>
            </div>
            <div v-else class="text-sm text-fg-subtle">No data</div>
        </div>
    </div>
</template>
