<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BenchmarkExpectedPrice } from './types';

defineProps<{
    /** Server validation errors keyed like `scoring.expected.0.model`. */
    errors: Record<string, string | undefined>;
}>();

const prices = defineModel<BenchmarkExpectedPrice[]>('prices', {
    required: true,
});
const tolerance = defineModel<number | string>('tolerance', {
    required: true,
});

function add(): void {
    prices.value = [
        ...prices.value,
        {
            provider: '',
            model: '',
            input_per_mtok: '',
            output_per_mtok: '',
            cache_read_per_mtok: '',
            cache_write_per_mtok: '',
        },
    ];
}

function remove(index: number): void {
    prices.value = prices.value.filter((_, i) => i !== index);
}

const FIELDS: Array<{
    key: keyof BenchmarkExpectedPrice;
    label: string;
}> = [
    { key: 'input_per_mtok', label: 'Input $/Mtok' },
    { key: 'output_per_mtok', label: 'Output $/Mtok' },
    { key: 'cache_read_per_mtok', label: 'Cache read $/Mtok' },
    { key: 'cache_write_per_mtok', label: 'Cache write $/Mtok' },
];
</script>

<template>
    <div class="grid gap-3" data-benchmark-prices>
        <div class="flex items-end justify-between gap-3">
            <label class="grid gap-1 text-[12px] text-muted-foreground">
                Tolerance (%)
                <Input
                    v-model="tolerance"
                    type="number"
                    min="0"
                    max="50"
                    step="any"
                    class="h-8 w-28 text-sm"
                    data-benchmark-tolerance
                />
                <InputError :message="errors['scoring.tolerance_percent']" />
            </label>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-7 gap-1 text-xs"
                data-benchmark-price-add
                @click="add"
            >
                <Plus class="size-3.5" />Add price
            </Button>
        </div>
        <div
            v-for="(price, index) in prices"
            :key="index"
            class="grid gap-2 rounded-lg border border-border bg-card p-3 sm:grid-cols-2"
            data-benchmark-price-row
        >
            <label class="grid gap-1 text-[12px] text-muted-foreground">
                Provider
                <Input
                    v-model="price.provider"
                    class="h-8 text-sm"
                    data-benchmark-price-provider
                />
                <InputError
                    :message="errors[`scoring.expected.${index}.provider`]"
                />
            </label>
            <label class="grid gap-1 text-[12px] text-muted-foreground">
                Model
                <Input
                    v-model="price.model"
                    class="h-8 text-sm"
                    data-benchmark-price-model
                />
                <InputError
                    :message="errors[`scoring.expected.${index}.model`]"
                />
            </label>
            <label
                v-for="field in FIELDS"
                :key="field.key"
                class="grid gap-1 text-[12px] text-muted-foreground"
            >
                {{ field.label }}
                <Input
                    v-model="price[field.key]"
                    type="number"
                    min="0"
                    step="any"
                    class="h-8 text-sm"
                    :data-benchmark-price-field="field.key"
                />
                <InputError
                    :message="errors[`scoring.expected.${index}.${field.key}`]"
                />
            </label>
            <div class="flex items-end justify-end sm:col-span-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 gap-1 text-xs text-destructive"
                    data-benchmark-price-remove
                    @click="remove(index)"
                >
                    <Trash2 class="size-3.5" />Remove
                </Button>
            </div>
        </div>
        <p
            v-if="prices.length === 0"
            class="rounded-lg border border-dashed border-border p-3 text-[12.5px] text-muted-foreground"
        >
            No expected prices yet.
        </p>
    </div>
</template>
