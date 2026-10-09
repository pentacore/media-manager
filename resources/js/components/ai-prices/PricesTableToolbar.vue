<script setup lang="ts">
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ALL_PROVIDERS } from './priceTable';

defineProps<{
    providerOptions: string[];
}>();

const provider = defineModel<string>('provider', { required: true });
const search = defineModel<string>('search', { required: true });
const showBatch = defineModel<boolean>('showBatch', { required: true });
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3"
    >
        <span
            class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
        >
            Configured models
        </span>
        <div class="flex flex-wrap items-center gap-2">
            <Select v-model="provider">
                <SelectTrigger
                    class="h-8 w-40 text-[12.5px]"
                    aria-label="Filter by provider"
                    data-price-filter-provider
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        :value="ALL_PROVIDERS"
                        aria-label="All providers"
                    >
                        All providers
                    </SelectItem>
                    <SelectItem
                        v-for="provider in providerOptions"
                        :key="provider"
                        :value="provider"
                        :aria-label="provider"
                    >
                        {{ provider }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Input
                v-model="search"
                type="search"
                placeholder="Search models"
                aria-label="Search models"
                class="h-8 w-48 text-[12.5px]"
                data-price-filter-search
            />
        </div>
        <div
            class="inline-flex items-center rounded-md border border-border bg-bg-elev p-0.5 text-[12px]"
            role="tablist"
            aria-label="Pricing tier"
        >
            <button
                type="button"
                role="tab"
                :aria-selected="!showBatch"
                class="h-6 rounded px-2.5 font-medium transition-colors"
                :class="
                    !showBatch
                        ? 'bg-card text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="showBatch = false"
            >
                Standard
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="showBatch"
                class="h-6 rounded px-2.5 font-medium transition-colors"
                :class="
                    showBatch
                        ? 'bg-card text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="showBatch = true"
            >
                Batch (50%)
            </button>
        </div>
    </div>
</template>
