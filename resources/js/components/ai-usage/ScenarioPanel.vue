<script setup lang="ts">
import { ChevronDown, ChevronRight, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PricedModel, ScenarioRates } from './types';

/**
 * The collapsible what-if panel. It edits a local copy of the rates and emits
 * them on Apply; the page turns that into a visit. Opens by default while a
 * scenario is active.
 */
const props = defineProps<{
    scenario: ScenarioRates | null;
    pricedModels: PricedModel[];
}>();

const emit = defineEmits<{
    apply: [rates: ScenarioRates];
    clear: [];
}>();

const scenarioActive = computed(() => props.scenario !== null);
const panelOpen = ref(scenarioActive.value);

const form = ref({
    input: props.scenario?.input ?? 0,
    output: props.scenario?.output ?? 0,
    cache_read: props.scenario?.cache_read ?? 0,
    cache_write: props.scenario?.cache_write ?? 0,
    reasoning: props.scenario?.reasoning ?? 0,
});

const selectedLoadKey = ref<string>('');

function loadFromModel(key: string) {
    selectedLoadKey.value = key;

    if (!key) {
        return;
    }

    const [provider, model] = key.split('|');
    const priced = props.pricedModels.find(
        (p) => p.provider === provider && p.model === model,
    );

    if (!priced) {
        return;
    }

    form.value = {
        input: parseFloat(priced.input_per_mtok),
        output: parseFloat(priced.output_per_mtok),
        cache_read: parseFloat(priced.cache_read_per_mtok),
        cache_write: parseFloat(priced.cache_write_per_mtok),
        reasoning: parseFloat(priced.reasoning_per_mtok),
    };
}
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        data-usage-scenario
    >
        <button
            type="button"
            class="flex w-full items-center justify-between border-b border-border px-4 py-3 hover:bg-bg-hover"
            data-usage-scenario-toggle
            @click="panelOpen = !panelOpen"
        >
            <span class="flex items-center gap-2">
                <ChevronDown v-if="panelOpen" class="size-4" />
                <ChevronRight v-else class="size-4" />
                <Sparkles class="size-3.5 text-accent" />
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                    >What-if scenario</span
                >
                <Pill v-if="scenarioActive" variant="info">active</Pill>
            </span>
            <span class="text-xs text-muted-foreground"
                >Recompute against hypothetical rates</span
            >
        </button>
        <div v-if="panelOpen" class="space-y-4 p-4">
            <div class="space-y-2">
                <Label class="text-xs">Load rates from existing model</Label>
                <Select
                    :model-value="selectedLoadKey"
                    @update:model-value="
                        (value) =>
                            loadFromModel(
                                typeof value === 'string' ? value : '',
                            )
                    "
                >
                    <SelectTrigger class="h-8 text-sm" data-usage-scenario-load>
                        <SelectValue
                            placeholder="Pick a priced model to copy its rates…"
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="model in pricedModels"
                            :key="`${model.provider}|${model.model}`"
                            :value="`${model.provider}|${model.model}`"
                        >
                            {{ model.provider }} / {{ model.model }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-3 md:grid-cols-5">
                <div
                    v-for="field in [
                        ['input', 'Input'],
                        ['output', 'Output'],
                        ['cache_read', 'Cache read'],
                        ['cache_write', 'Cache write'],
                        ['reasoning', 'Reasoning'],
                    ] as const"
                    :key="field[0]"
                    class="space-y-1"
                >
                    <Label :for="`rate_${field[0]}`" class="text-xs">
                        {{ field[1] }}
                    </Label>
                    <Input
                        :id="`rate_${field[0]}`"
                        type="number"
                        step="0.0001"
                        min="0"
                        class="h-8 font-mono text-xs"
                        v-model.number="
                            form[
                                field[0] as
                                    | 'input'
                                    | 'output'
                                    | 'cache_read'
                                    | 'cache_write'
                                    | 'reasoning'
                            ]
                        "
                    />
                </div>
            </div>

            <div class="flex gap-2">
                <Button
                    size="sm"
                    class="h-7 text-xs"
                    data-usage-scenario-apply
                    @click="emit('apply', { ...form })"
                >
                    Apply
                </Button>
                <Button
                    v-if="scenarioActive"
                    size="sm"
                    variant="outline"
                    class="h-7 text-xs"
                    data-usage-scenario-clear
                    @click="emit('clear')"
                >
                    Clear
                </Button>
            </div>
        </div>
    </div>
</template>
