<script setup lang="ts">
import { computed } from 'vue';
import ModelSelect from '@/components/ai/ModelSelect.vue';
import ReasoningSelect from '@/components/ai/ReasoningSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { AiReasoningLevel } from '@/typefinder';
import type { ChatOverride, ModelOptions } from './types';

const props = defineProps<{
    options: ModelOptions | null;
    disabled: boolean;
}>();

const override = defineModel<ChatOverride>({ required: true });

const emit = defineEmits<{
    (e: 'change', value: ChatOverride): void;
}>();

const overridden = computed(
    () => override.value.model !== null || override.value.reasoning !== null,
);

const effectiveProvider = computed(
    () => override.value.provider ?? props.options?.defaults.provider ?? '',
);

const effectiveModel = computed(
    () => override.value.model ?? props.options?.defaults.model ?? '',
);

const effectiveReasoningLabel = computed(() => {
    if (override.value.reasoning === null) {
        return props.options?.defaults.reasoning_label ?? '';
    }

    return (
        props.options?.reasoningLevels.find(
            (level) => level.value === override.value.reasoning,
        )?.label ?? override.value.reasoning
    );
});

const capability = computed(
    () =>
        props.options?.modelCapabilities[
            `${effectiveProvider.value}|${effectiveModel.value}`
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
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                :disabled="disabled || options === null"
                :data-overridden="overridden ? 'true' : 'false'"
                :title="
                    options
                        ? `${effectiveProvider} · ${effectiveModel} · ${effectiveReasoningLabel}`
                        : undefined
                "
                data-chat-model-chip
                :class="
                    cn(
                        'font-mono-tabular inline-flex h-6 max-w-[260px] min-w-0 items-center rounded px-2 text-xs transition-colors disabled:opacity-50',
                        overridden
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                    )
                "
            >
                <span class="truncate">
                    <template v-if="options">
                        {{ effectiveModel }} · {{ effectiveReasoningLabel }}
                    </template>
                    <template v-else>Default model</template>
                </span>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            class="flex w-80 flex-col gap-3"
            data-chat-model-picker
        >
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
        </PopoverContent>
    </Popover>
</template>
