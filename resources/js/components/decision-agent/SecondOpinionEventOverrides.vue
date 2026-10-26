<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import AiModelsController from '@/actions/App/Http/Controllers/Admin/AiModelsController';
import InputError from '@/components/InputError.vue';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SecondOpinionEngine, SecondOpinionMode } from '@/typefinder';
import type { SecondOpinionEventOverride } from './types';

const props = defineProps<{
    /** The live event allowlist from the page form. */
    allowlist: string[];
    modes: Array<{ value: SecondOpinionMode; label: string }>;
    engines: Array<{ value: SecondOpinionEngine; label: string }>;
    defaultEngine: SecondOpinionEngine;
    defaultThreshold: number;
    classifierConfigured: boolean;
    errors: Record<string, string | undefined>;
}>();

const emit = defineEmits<{ 'structure-change': [] }>();

const overrides = defineModel<SecondOpinionEventOverride[]>('overrides', {
    required: true,
});

const modelLinkHref = `${AiModelsController.index.url()}#event-overrides-decision_verifier`;

const defaultEngineLabel = computed(
    () =>
        props.engines.find((engine) => engine.value === props.defaultEngine)
            ?.label ?? props.defaultEngine,
);

const addableEvents = computed<string[]>(() =>
    props.allowlist.filter(
        (key) =>
            !overrides.value.some((override) => override.event_key === key),
    ),
);

function usesClassifier(override: SecondOpinionEventOverride): boolean {
    return (override.engine ?? props.defaultEngine) !== 'llm';
}

function errorFor(index: number): string | undefined {
    return ['event_key', 'mode', 'engine', 'jev_threshold']
        .map(
            (field) =>
                props.errors[
                    `second_opinion_event_overrides.${index}.${field}`
                ],
        )
        .find(Boolean);
}

function addOverride(eventKey: string): void {
    overrides.value.push({
        event_key: eventKey,
        mode: null,
        engine: null,
        jev_threshold: null,
    });
}

function removeOverride(eventKey: string): void {
    overrides.value = overrides.value.filter(
        (override) => override.event_key !== eventKey,
    );
    emit('structure-change');
}

function setMode(override: SecondOpinionEventOverride, value: unknown): void {
    override.mode =
        value === 'inherit' ? null : (String(value) as SecondOpinionMode);
}

/** An engine that never asks Jev drops the threshold it would ignore. */
function setEngine(override: SecondOpinionEventOverride, value: unknown): void {
    override.engine =
        value === 'inherit' ? null : (String(value) as SecondOpinionEngine);

    if (!usesClassifier(override)) {
        override.jev_threshold = null;
    }
}

function setThreshold(
    override: SecondOpinionEventOverride,
    value: string | number,
): void {
    override.jev_threshold = value === '' ? null : Number(value);
}
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-lg border border-border p-3"
        data-second-opinion-event-overrides
    >
        <div class="text-[12px] font-semibold text-muted-foreground">
            Per-event overrides
        </div>
        <p class="text-[12px] text-fg-subtle">
            An event's mode replaces the per-action approval rules for actions
            proposed on that event.
        </p>
        <div
            v-for="(override, index) in overrides"
            :key="override.event_key"
            class="flex flex-col gap-2"
            :data-second-opinion-override="override.event_key"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono-tabular text-[13px]">
                    {{ override.event_key }}
                </span>
                <Pill
                    v-if="!allowlist.includes(override.event_key)"
                    variant="warn"
                    data-override-not-enabled
                >
                    Event not enabled
                </Pill>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="ml-auto"
                    :aria-label="`Remove the ${override.event_key} override`"
                    data-remove-second-opinion-override
                    @click="removeOverride(override.event_key)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Select
                    :model-value="override.mode ?? 'inherit'"
                    @update:model-value="(value) => setMode(override, value)"
                >
                    <SelectTrigger
                        class="h-8 w-full text-sm sm:w-[220px]"
                        :aria-label="`${override.event_key} mode`"
                        data-override-mode
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            value="inherit"
                            data-override-mode-option="inherit"
                        >
                            Use action rules
                        </SelectItem>
                        <SelectItem
                            v-for="mode in modes"
                            :key="mode.value"
                            :value="mode.value"
                            :data-override-mode-option="mode.value"
                        >
                            {{ mode.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select
                    :model-value="override.engine ?? 'inherit'"
                    @update:model-value="(value) => setEngine(override, value)"
                >
                    <SelectTrigger
                        class="h-8 w-full text-sm sm:w-[220px]"
                        :aria-label="`${override.event_key} engine`"
                        data-override-engine
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            value="inherit"
                            data-override-engine-option="inherit"
                        >
                            {{ `Use default (${defaultEngineLabel})` }}
                        </SelectItem>
                        <SelectItem
                            v-for="engine in engines"
                            :key="engine.value"
                            :value="engine.value"
                            :disabled="
                                engine.value !== 'llm' && !classifierConfigured
                            "
                            :data-override-engine-option="engine.value"
                        >
                            {{ engine.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Input
                    v-if="usesClassifier(override)"
                    type="number"
                    min="0"
                    max="1"
                    step="0.05"
                    :placeholder="String(defaultThreshold)"
                    :model-value="override.jev_threshold ?? ''"
                    class="h-8 w-[120px] text-sm"
                    :aria-label="`${override.event_key} Jev threshold`"
                    data-override-threshold
                    @update:model-value="
                        (value) => setThreshold(override, value)
                    "
                />
            </div>
            <Link
                :href="modelLinkHref"
                class="w-fit text-[12px] underline underline-offset-2"
                data-override-model-link
            >
                Model: set on AI Models
            </Link>
            <InputError :message="errorFor(index)" />
        </div>
        <div v-if="addableEvents.length > 0" data-add-second-opinion-override>
            <!-- Held empty so every pick emits and the placeholder returns. -->
            <Select
                :model-value="''"
                @update:model-value="(value) => addOverride(String(value))"
            >
                <SelectTrigger class="h-8 w-full max-w-[320px] text-sm">
                    <Plus class="size-4" />
                    <SelectValue placeholder="Add an event" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="eventKey in addableEvents"
                        :key="eventKey"
                        :value="eventKey"
                        :data-second-opinion-override-option="eventKey"
                    >
                        {{ eventKey }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>
        <p v-else class="text-[12px] text-muted-foreground">
            Every enabled event already has an override, or no events are
            enabled above.
        </p>
    </div>
</template>
