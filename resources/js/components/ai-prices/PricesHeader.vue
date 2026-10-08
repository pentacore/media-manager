<script setup lang="ts">
import { RefreshCcw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import AddFromCatalogDialog from './AddFromCatalogDialog.vue';
import CreatePriceDialog from './CreatePriceDialog.vue';
import type { PoolRow } from './types';

defineProps<{
    refreshing: boolean;
    catalogProviders: string[];
    pools: PoolRow[];
    rateLimitMetrics: Array<{ value: string; label: string }>;
    rateLimitPeriods: Array<{ value: string; label: string }>;
}>();

const emit = defineEmits<{ refresh: [] }>();
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> AI prices
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                AI prices
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Per-million-token rates used to estimate cost on the AI Usage
                dashboard. Add a row for any model you've used so its spend
                shows up.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                class="h-7 gap-1.5 text-xs"
                :disabled="refreshing"
                @click="emit('refresh')"
            >
                <RefreshCcw
                    class="size-3.5"
                    :class="{ 'animate-spin': refreshing }"
                />Refresh online
            </Button>
            <AddFromCatalogDialog :providers="catalogProviders" />
            <CreatePriceDialog
                :pools="pools"
                :rate-limit-metrics="rateLimitMetrics"
                :rate-limit-periods="rateLimitPeriods"
                :catalog-providers="catalogProviders"
            />
        </div>
    </div>
</template>
