<script setup lang="ts">
import { computed } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import ChatModelOptions from './ChatModelOptions.vue';
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
            <ChatModelOptions
                v-model="override"
                :options="options"
                :disabled="disabled"
                @change="(value) => emit('change', value)"
            />
        </PopoverContent>
    </Popover>
</template>
