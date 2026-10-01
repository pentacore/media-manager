<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import InputError from '@/components/InputError.vue';
import { RateLimitEditor, Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import CatalogModelList from './CatalogModelList.vue';
import type { CatalogModelOption, RateLimitDraft } from './types';

const props = defineProps<{
    pools: { id: number; name: string }[];
    rateLimitMetrics: Array<{ value: string; label: string }>;
    rateLimitPeriods: Array<{ value: string; label: string }>;
    catalogProviders: string[];
}>();

const showCreateDialog = ref(false);
const createRateLimits = ref<RateLimitDraft[]>([]);
const createPoolId = ref('none');

// Whether a manually managed row opts into online refreshes. Create defaults
// OFF so a hand-entered price stays locked; edit mirrors the row's state.
const createAutomaticUpdates = ref(false);

type RateColumn =
    | 'input_per_mtok'
    | 'output_per_mtok'
    | 'cache_read_per_mtok'
    | 'cache_write_per_mtok'
    | 'reasoning_per_mtok'
    | 'search_unit_per_k';

function blankRates(): Record<RateColumn, string> {
    return {
        input_per_mtok: '',
        output_per_mtok: '',
        cache_read_per_mtok: '',
        cache_write_per_mtok: '',
        reasoning_per_mtok: '',
        search_unit_per_k: '',
    };
}

const provider = ref('');
const model = ref('');
const rates = ref<Record<RateColumn, string>>(blankRates());
const fromCatalog = ref(false);
const showCatalogPicker = ref(false);

// Catalog provider ids are lowercase, so "OpenRouter " still offers the picker.
const normalizedProvider = computed(() => provider.value.trim().toLowerCase());
const catalogAvailable = computed(() =>
    props.catalogProviders.includes(normalizedProvider.value),
);

function applyCatalogPick(option: CatalogModelOption): void {
    provider.value = normalizedProvider.value;
    model.value = option.model;

    for (const column of Object.keys(rates.value) as RateColumn[]) {
        const value = option.prices[column];
        rates.value[column] =
            value === null
                ? column === 'search_unit_per_k'
                    ? ''
                    : '0'
                : String(Number(value));
    }

    createAutomaticUpdates.value = true;
    fromCatalog.value = true;
    showCatalogPicker.value = false;
}

// Editing any rate after a catalog pick makes the row the admin's own price.
function onRateInput(): void {
    if (fromCatalog.value) {
        fromCatalog.value = false;
        createAutomaticUpdates.value = false;
    }
}

function onProviderOrModelInput(): void {
    if (fromCatalog.value) {
        fromCatalog.value = false;
    }
}

function resetForm(): void {
    createRateLimits.value = [];
    createPoolId.value = 'none';
    createAutomaticUpdates.value = false;
    provider.value = '';
    model.value = '';
    rates.value = blankRates();
    fromCatalog.value = false;
    showCatalogPicker.value = false;
}

// The inputs are controlled, so closing the dialog must clear them or the next
// open would show the abandoned values.
watch(showCreateDialog, (isOpen) => {
    if (!isOpen) {
        resetForm();
    }
});

function onCreateSuccess(): void {
    showCreateDialog.value = false;
    resetForm();
}
</script>

<template>
    <Dialog v-model:open="showCreateDialog">
        <DialogTrigger as-child>
            <Button size="sm" class="h-7 gap-1.5 text-xs" data-create-price>
                <Plus class="size-3.5" />Add model price
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add model price</DialogTitle>
            </DialogHeader>
            <Form
                v-bind="AiModelPriceController.store.form()"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="onCreateSuccess"
            >
                <div class="space-y-2">
                    <Label for="provider">Provider</Label>
                    <Input
                        id="provider"
                        v-model="provider"
                        name="provider"
                        placeholder="openai, anthropic, gemini, …"
                        @update:model-value="onProviderOrModelInput"
                    />
                    <InputError :message="errors.provider" />
                </div>
                <div v-if="catalogAvailable" class="space-y-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-7 px-2 text-xs"
                        data-catalog-pick-toggle
                        @click="showCatalogPicker = !showCatalogPicker"
                        >{{
                            showCatalogPicker
                                ? 'Hide catalog'
                                : 'Pick from catalog'
                        }}</Button
                    >
                    <CatalogModelList
                        v-if="showCatalogPicker"
                        :provider="normalizedProvider"
                        mode="single"
                        @select="applyCatalogPick"
                    />
                </div>
                <input
                    type="hidden"
                    name="from_catalog"
                    :value="fromCatalog ? '1' : '0'"
                />
                <div class="space-y-2">
                    <Label for="model">Model</Label>
                    <Input
                        id="model"
                        v-model="model"
                        name="model"
                        placeholder="gpt-5-mini"
                        @update:model-value="onProviderOrModelInput"
                    />
                    <InputError :message="errors.model" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div
                        v-for="field in [
                            ['input_per_mtok', 'Input ($/M)'],
                            ['output_per_mtok', 'Output ($/M)'],
                            ['cache_read_per_mtok', 'Cache Read ($/M)'],
                            ['cache_write_per_mtok', 'Cache Write ($/M)'],
                        ] as const"
                        :key="field[0]"
                        class="space-y-2"
                    >
                        <Label :for="field[0]">{{ field[1] }}</Label>
                        <Input
                            :id="field[0]"
                            v-model="rates[field[0]]"
                            :name="field[0]"
                            type="number"
                            step="0.0001"
                            min="0"
                            @update:model-value="onRateInput"
                        />
                        <InputError
                            :message="
                                errors[
                                    field[0] as
                                        | 'input_per_mtok'
                                        | 'output_per_mtok'
                                        | 'cache_read_per_mtok'
                                        | 'cache_write_per_mtok'
                                ]
                            "
                        />
                    </div>
                    <div class="col-span-2 space-y-2">
                        <Label for="reasoning_per_mtok">Reasoning ($/M)</Label>
                        <Input
                            id="reasoning_per_mtok"
                            v-model="rates.reasoning_per_mtok"
                            name="reasoning_per_mtok"
                            type="number"
                            step="0.0001"
                            min="0"
                            @update:model-value="onRateInput"
                        />
                        <InputError :message="errors.reasoning_per_mtok" />
                    </div>
                    <div class="col-span-2 space-y-2" data-search-unit-field>
                        <Label for="search_unit_per_k"
                            >Search units ($/1k)</Label
                        >
                        <Input
                            id="search_unit_per_k"
                            v-model="rates.search_unit_per_k"
                            name="search_unit_per_k"
                            type="number"
                            step="0.0001"
                            min="0"
                            @update:model-value="onRateInput"
                        />
                        <p class="text-[11px] text-fg-subtle">
                            Rerank models only — price per 1,000 searches.
                        </p>
                        <InputError :message="errors.search_unit_per_k" />
                    </div>
                    <div class="col-span-2 space-y-2">
                        <Label for="free_usage_pool_id">Free usage pool</Label>
                        <input
                            type="hidden"
                            name="free_usage_pool_id"
                            :value="createPoolId === 'none' ? '' : createPoolId"
                        />
                        <Select id="free_usage_pool_id" v-model="createPoolId">
                            <SelectTrigger class="h-9 w-full text-sm">
                                <SelectValue placeholder="No pool" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none"> No pool </SelectItem>
                                <SelectItem
                                    v-for="pool in pools"
                                    :key="pool.id"
                                    :value="String(pool.id)"
                                >
                                    {{ pool.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.free_usage_pool_id" />
                    </div>
                    <div class="col-span-2 space-y-2">
                        <Label>Automatic pricing updates</Label>
                        <input
                            type="hidden"
                            name="automatic_updates_enabled"
                            :value="createAutomaticUpdates ? '1' : '0'"
                        />
                        <Toggle
                            v-model="createAutomaticUpdates"
                            role="switch"
                            aria-label="Automatic pricing updates"
                            :aria-checked="createAutomaticUpdates"
                            :label="
                                createAutomaticUpdates
                                    ? 'On — kept in sync online'
                                    : 'Off — locked to manual price'
                            "
                        />
                        <p class="text-[11px] text-fg-subtle">
                            Off locks this row so an online refresh never
                            overwrites your entered price.
                        </p>
                        <InputError
                            :message="errors.automatic_updates_enabled"
                        />
                    </div>
                    <RateLimitEditor
                        v-model="createRateLimits"
                        :metrics="rateLimitMetrics"
                        :periods="rateLimitPeriods"
                        :errors="errors"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-create-price-submit
                        >Save</Button
                    >
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
