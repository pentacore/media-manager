<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import TierListEditor from '@/components/ai/TierListEditor.vue';
import type { ModelPool } from '@/components/ai/TierListEditor.vue';
import InputError from '@/components/InputError.vue';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AiReasoningLevel, AiTask } from '@/typefinder';
import { eventOverrideHooks } from './eventOverrideHooks';
import type {
    EventOverrideForm,
    EventOverrideRow,
    ResolvedSummary,
} from './types';

const props = defineProps<{
    task: AiTask;
    taskLabel: string;
    /** This task's saved overrides. */
    saved: EventOverrideRow[];
    /** What an unsaved override inherits: the task row's resolved pair. */
    fallbackInherited: ResolvedSummary;
    allowlistedEvents: string[];
    models: Record<string, string[]>;
    reasoningLevels: Array<{ label: string; value: AiReasoningLevel }>;
    modelCapabilities: Record<
        string,
        {
            supports_reasoning: boolean | null;
            levels: AiReasoningLevel[] | null;
        }
    >;
    reasoningProviders: string[];
    modelPools: Record<string, ModelPool>;
    errors: Record<string, string | undefined>;
}>();

const emit = defineEmits<{ 'structure-change': [prefix: string] }>();

/** The page's whole flat list; this section edits only its own task's rows. */
const overrides = defineModel<EventOverrideForm[]>('overrides', {
    required: true,
});

const hooks = computed(() => eventOverrideHooks(props.task));

const inheritLabel = computed(() => `Same as ${props.taskLabel.toLowerCase()}`);

/** This task's rows, each with its position in the full list for error keys. */
const entries = computed(() =>
    overrides.value
        .map((override, index) => ({ override, index }))
        .filter(({ override }) => override.task === props.task),
);

const savedByEvent = computed<Record<string, EventOverrideRow>>(() =>
    Object.fromEntries(props.saved.map((row) => [row.event_key, row])),
);

const addableEvents = computed<string[]>(() =>
    props.allowlistedEvents.filter(
        (key) =>
            !entries.value.some(({ override }) => override.event_key === key),
    ),
);

/**
 * What an override's inherit tier runs on: a saved override's own summary; a
 * newly added one hands off to the task's default list until it is saved.
 */
function inherited(eventKey: string): ResolvedSummary {
    return savedByEvent.value[eventKey]?.inherited ?? props.fallbackInherited;
}

function resolved(eventKey: string): ResolvedSummary | undefined {
    return savedByEvent.value[eventKey]?.resolved;
}

function addOverride(eventKey: string): void {
    overrides.value.push({
        task: props.task,
        event_key: eventKey,
        tiers: [
            {
                provider: null,
                model: null,
                reasoning: null,
                min_pool_percent: null,
                min_pool_tokens: null,
            },
        ],
    });
}

function removeOverride(eventKey: string): void {
    overrides.value = overrides.value.filter(
        (override) =>
            override.task !== props.task || override.event_key !== eventKey,
    );
}
</script>

<template>
    <div
        class="mt-2 flex flex-col gap-3 rounded-lg border border-border p-3"
        :data-event-overrides="task"
        :id="`event-overrides-${task}`"
    >
        <div class="text-[12px] font-semibold text-muted-foreground">
            Event overrides
        </div>
        <div
            v-for="{ override, index } in entries"
            :key="override.event_key"
            class="flex flex-col gap-2"
            v-bind="{ [hooks.override]: override.event_key }"
        >
            <div class="flex items-center gap-2">
                <span class="font-mono-tabular text-[13px]">
                    {{ override.event_key }}
                </span>
                <Pill
                    v-if="!allowlistedEvents.includes(override.event_key)"
                    variant="warn"
                >
                    Event not enabled
                </Pill>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="ml-auto"
                    :aria-label="`Remove the ${override.event_key} override`"
                    v-bind="{ [hooks.remove]: '' }"
                    @click="removeOverride(override.event_key)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
            <TierListEditor
                v-model="override.tiers"
                :label="override.event_key"
                :models="models"
                :reasoning-levels="reasoningLevels"
                :model-capabilities="modelCapabilities"
                :reasoning-providers="reasoningProviders"
                :model-pools="modelPools"
                :inherited-provider="inherited(override.event_key).provider"
                :inherited-model="inherited(override.event_key).model"
                :inherit-label="inheritLabel"
                :reasoning-inherit-label="inheritLabel"
                :error-prefix="`event_overrides.${index}.tiers`"
                :errors="errors"
                @structure-change="
                    emit('structure-change', `event_overrides.${index}.tiers`)
                "
            />
            <div
                v-if="resolved(override.event_key)"
                class="flex flex-wrap items-center gap-2"
            >
                <p
                    class="font-mono-tabular text-[12px] text-muted-foreground"
                    v-bind="{ [hooks.resolved]: '' }"
                >
                    →
                    {{ resolved(override.event_key)?.provider }}
                    ·
                    {{ resolved(override.event_key)?.model }}
                    ·
                    {{ resolved(override.event_key)?.reasoning_label }}
                </p>
                <Pill
                    v-if="resolved(override.event_key)?.tier"
                    :title="
                        resolved(override.event_key)?.tier?.reason ?? undefined
                    "
                    data-tier-live
                >
                    Live:
                    <template v-if="resolved(override.event_key)?.tier?.via">
                        via
                        {{ resolved(override.event_key)?.tier?.via }}
                        ·
                    </template>
                    tier
                    {{ resolved(override.event_key)?.tier?.position }}
                    of
                    {{ resolved(override.event_key)?.tier?.count }}
                </Pill>
            </div>
            <p
                v-if="savedByEvent[override.event_key]?.warning"
                class="text-[12px] text-warning"
                v-bind="{ [hooks.warning]: '' }"
            >
                {{ savedByEvent[override.event_key]?.warning }}
            </p>
            <InputError
                :message="errors[`event_overrides.${index}.event_key`]"
            />
        </div>
        <div v-if="addableEvents.length > 0" v-bind="{ [hooks.add]: '' }">
            <!-- Held empty so every pick emits and the placeholder returns. -->
            <Select
                :model-value="''"
                @update:model-value="(value) => addOverride(String(value))"
            >
                <SelectTrigger class="h-8 max-w-[320px] text-sm">
                    <Plus class="size-4" />
                    <SelectValue placeholder="Add an event override" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="eventKey in addableEvents"
                        :key="eventKey"
                        :value="eventKey"
                        v-bind="{ [hooks.option]: eventKey }"
                    >
                        {{ eventKey }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>
        <p v-else class="text-[12px] text-muted-foreground">
            Every enabled event already has an override, or no events are
            enabled on the Decision Agent page.
        </p>
    </div>
</template>
