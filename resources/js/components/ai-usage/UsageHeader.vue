<script setup lang="ts">
import { Download } from '@lucide/vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import { TimeWindowFilter } from '@/components/mm';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { KindOption, TierFilter, WindowKey, WindowOption } from './types';

defineProps<{
    kind: string | null;
    kinds: KindOption[];
    tier: TierFilter | null;
    windowKey: WindowKey;
    windows: WindowOption[];
    exportUrl: string;
}>();

const emit = defineEmits<{
    kindChange: [value: string];
    tierChange: [value: string];
    windowChange: [value: string];
}>();
</script>

<template>
    <div class="flex items-end justify-between gap-3">
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> AI usage
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                AI usage
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Per-call ledger with token counts, latency, and cost. Pricing
                pulled from
                <a
                    :href="AiModelPriceController.index.url()"
                    class="underline hover:text-foreground"
                    >model prices</a
                >.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <div data-usage-kind-filter>
                <Select
                    :model-value="kind ?? 'all'"
                    @update:model-value="
                        (value) =>
                            emit(
                                'kindChange',
                                typeof value === 'string' ? value : 'all',
                            )
                    "
                >
                    <SelectTrigger
                        id="usage-kind"
                        class="h-7 w-[150px] text-xs"
                        aria-label="Usage kind"
                    >
                        <SelectValue placeholder="All kinds" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all" aria-label="All kinds"
                            >All kinds</SelectItem
                        >
                        <SelectItem
                            v-for="option in kinds"
                            :key="option.value"
                            :value="option.value"
                            :aria-label="option.label"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div data-usage-tier-filter>
                <Select
                    :model-value="tier ?? 'all'"
                    @update:model-value="
                        (value) =>
                            emit(
                                'tierChange',
                                typeof value === 'string' ? value : 'all',
                            )
                    "
                >
                    <SelectTrigger
                        id="usage-tier"
                        class="h-7 w-[150px] text-xs"
                        aria-label="Model tier"
                    >
                        <SelectValue placeholder="All tiers" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all" aria-label="All tiers"
                            >All tiers</SelectItem
                        >
                        <SelectItem value="first" aria-label="Tier 1"
                            >Tier 1</SelectItem
                        >
                        <SelectItem
                            value="fell_through"
                            aria-label="Fell through"
                            >Fell through</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <TimeWindowFilter
                :options="windows"
                :model-value="windowKey"
                @update:model-value="(value) => emit('windowChange', value)"
            />
            <a
                :href="exportUrl"
                data-usage-export
                class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-card px-2 text-xs font-medium text-foreground transition-colors hover:bg-bg-hover"
            >
                <Download class="size-3.5" />Export CSV
            </a>
        </div>
    </div>
</template>
