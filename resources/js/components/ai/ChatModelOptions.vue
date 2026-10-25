<script setup lang="ts">
import { computed } from 'vue';
import ModelSelect from '@/components/ai/ModelSelect.vue';
import ReasoningSelect from '@/components/ai/ReasoningSelect.vue';
import { Button } from '@/components/ui/button';
import type { AiReasoningLevel } from '@/typefinder';
import { effectiveModel, isOverridden } from './chatOverride';
import type { ChatOverride, ModelOptions } from './types';

/**
 * The chat's model and reasoning override: the body of the desktop model
 * chip's popover (`ChatModelPicker`) and of the phone header's ⋯ panel.
 */
const props = defineProps<{
    options: ModelOptions | null;
    disabled: boolean;
}>();

const override = defineModel<ChatOverride>({ required: true });

const emit = defineEmits<{
    (e: 'change', value: ChatOverride): void;
}>();

const overridden = computed(() => isOverridden(override.value));

const effective = computed(() => effectiveModel(override.value, props.options));
const effectiveProvider = computed(() => effective.value.provider);
const effectiveModelName = computed(() => effective.value.model);

const capability = computed(
    () =>
        props.options?.modelCapabilities[
            `${effectiveProvider.value}|${effectiveModelName.value}`
        ] ?? null,
);

const reasoningHint = computed<string | null>(() => {
    if (props.options === null) {
        return null;
    }

    if (!props.options.reasoningProviders.includes(effectiveProvider.value)) {
        return 'Not supported by this provider';
    }

    return capability.value?.supports_reasoning === false
        ? "Model doesn't reason"
        : null;
});

/**
 * The edit being built from one pick. ModelSelect writes the provider and
 * then the model for a single choice, and the parent's copy of the override
 * only catches up on the next render, so the pieces accumulate here.
 */
let pending: ChatOverride | null = null;
let flushQueued = false;

function stage(patch: Partial<ChatOverride>): void {
    pending = { ...(pending ?? override.value), ...patch };
    override.value = pending;
}

function flush(): void {
    if (pending === null) {
        return;
    }

    const value = pending;
    pending = null;
    emit('change', value);
}

/**
 * The provider half of a pick: staged without emitting. The model update
 * that follows emits; a microtask catches the rare pick where only the
 * provider changes (the model id is the same under both providers, so
 * ModelSelect never writes it).
 */
function onProvider(value: string): void {
    stage({ provider: value === '' ? null : value });

    if (!flushQueued) {
        flushQueued = true;
        queueMicrotask(() => {
            flushQueued = false;
            flush();
        });
    }
}

function onModel(value: string): void {
    stage({ model: value === '' ? null : value });
    flush();
}

function onReasoning(value: AiReasoningLevel | null): void {
    stage({ reasoning: value });
    flush();
}

function reset(): void {
    stage({ provider: null, model: null, reasoning: null });
    flush();
}
</script>

<template>
    <div class="flex flex-col gap-3" data-chat-model-options>
        <template v-if="options">
            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-medium text-muted-foreground">
                    Model
                </span>
                <ModelSelect
                    :models="options.models"
                    :inherit-label="`Default (${options.defaults.provider} · ${options.defaults.model})`"
                    :provider="override.provider ?? ''"
                    :model="override.model ?? ''"
                    :disabled="disabled"
                    data-chat-model-select
                    @update:provider="onProvider"
                    @update:model="onModel"
                />
            </div>
            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-medium text-muted-foreground">
                    Reasoning
                </span>
                <ReasoningSelect
                    :model-value="override.reasoning"
                    :levels="options.reasoningLevels"
                    :inherit-label="`Default (${options.defaults.reasoning_label})`"
                    :accepted="capability?.levels ?? null"
                    :disabled-hint="reasoningHint"
                    :disabled="disabled"
                    @update:model-value="onReasoning"
                />
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="self-start"
                :disabled="disabled || !overridden"
                data-chat-model-reset
                @click="reset"
            >
                Reset to defaults
            </Button>
        </template>
    </div>
</template>
