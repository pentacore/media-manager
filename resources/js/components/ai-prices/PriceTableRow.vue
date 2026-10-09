<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { Pill, TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    fmt,
    hasBatch,
    rateFor,
    safeSourceHref,
    sourceLabel,
    sourceVariant,
} from './priceTable';
import type { PriceRow } from './types';

defineProps<{
    price: PriceRow;
    showBatch: boolean;
    selected: boolean;
}>();

const emit = defineEmits<{
    toggle: [selected: boolean | 'indeterminate'];
    edit: [];
    delete: [];
}>();
</script>

<template>
    <tr
        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
        :class="showBatch && !hasBatch(price) ? 'opacity-50' : ''"
        :data-price-row="price.model"
    >
        <td class="py-2.5 pr-1 pl-3">
            <Checkbox
                :model-value="selected"
                :aria-label="`Select ${price.provider} / ${price.model}`"
                data-select-row
                @update:model-value="(value) => emit('toggle', value)"
            />
        </td>
        <td class="px-3 py-2.5">
            <div class="font-mono-tabular text-[12.5px] font-medium">
                {{ price.model }}
            </div>
            <div
                v-if="showBatch && !hasBatch(price)"
                class="text-[11px] text-fg-subtle"
            >
                no batch — showing standard
            </div>
        </td>
        <td class="px-3 py-2.5">
            <Pill>{{ price.provider }}</Pill>
        </td>
        <td class="font-mono-tabular px-3 py-2.5 text-right">
            {{
                fmt(
                    rateFor(price, 'input_per_mtok', showBatch) ??
                        price.input_per_mtok,
                )
            }}
        </td>
        <td class="font-mono-tabular px-3 py-2.5 text-right">
            {{
                fmt(
                    rateFor(price, 'output_per_mtok', showBatch) ??
                        price.output_per_mtok,
                )
            }}
        </td>
        <td class="font-mono-tabular px-3 py-2.5 text-right text-fg-subtle">
            {{
                fmt(
                    rateFor(price, 'cache_read_per_mtok', showBatch) ??
                        price.cache_read_per_mtok,
                )
            }}
        </td>
        <td class="font-mono-tabular px-3 py-2.5 text-right text-fg-subtle">
            {{
                fmt(
                    rateFor(price, 'cache_write_per_mtok', showBatch) ??
                        price.cache_write_per_mtok,
                )
            }}
        </td>
        <td class="font-mono-tabular px-3 py-2.5 text-right text-fg-subtle">
            {{
                fmt(
                    rateFor(price, 'reasoning_per_mtok', showBatch) ??
                        price.reasoning_per_mtok,
                )
            }}
        </td>
        <td class="px-3 py-2.5">
            <div class="flex flex-col gap-1">
                <template v-if="price.pricing_source">
                    <a
                        v-if="safeSourceHref(price.pricing_source_url)"
                        :href="safeSourceHref(price.pricing_source_url)!"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="w-fit"
                    >
                        <Pill
                            :variant="sourceVariant(price.pricing_source)"
                            data-price-source
                        >
                            {{ sourceLabel(price.pricing_source) }}
                        </Pill>
                    </a>
                    <Pill
                        v-else
                        :variant="sourceVariant(price.pricing_source)"
                        data-price-source
                    >
                        {{ sourceLabel(price.pricing_source) }}
                    </Pill>
                </template>
                <span v-else class="text-fg-subtle">—</span>
                <span class="text-[11px] text-fg-subtle">
                    {{
                        price.automatic_updates_enabled
                            ? 'Auto-updates on'
                            : 'Locked'
                    }}
                </span>
            </div>
        </td>
        <td class="px-3 py-2.5 text-muted-foreground">
            <TimeStamp :iso="price.pricing_synced_at" mode="smart" />
        </td>
        <td class="px-3 py-2.5 text-muted-foreground">
            <TimeStamp :iso="price.pricing_verified_at" mode="smart" />
        </td>
        <td class="px-3 py-2.5 text-right">
            <div class="flex justify-end gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    data-price-edit
                    @click="emit('edit')"
                >
                    Edit
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="size-7 p-0 text-destructive hover:text-destructive"
                    data-price-delete
                    @click="emit('delete')"
                >
                    <Trash2 class="size-3.5" />
                </Button>
            </div>
        </td>
    </tr>
</template>
