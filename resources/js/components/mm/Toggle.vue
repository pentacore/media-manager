<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        label?: string;
        disabled?: boolean;
    }>(),
    {
        label: undefined,
        disabled: false,
    },
);

const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void;
}>();

const labelText = computed(
    () => props.label ?? (props.modelValue ? 'on' : 'off'),
);

function toggle() {
    if (props.disabled) {
        return;
    }

    emit('update:modelValue', !props.modelValue);
}
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :class="
            cn(
                'inline-flex items-center gap-2 disabled:opacity-50',
                'cursor-pointer',
            )
        "
        @click="toggle"
    >
        <span
            :class="
                cn(
                    'relative h-[18px] w-[30px] rounded-full transition-colors',
                    modelValue ? 'bg-accent' : 'bg-border',
                )
            "
        >
            <span
                class="absolute top-0.5 size-[14px] rounded-full bg-bg-elev shadow transition-[left]"
                :class="modelValue ? 'left-[14px]' : 'left-0.5'"
            />
        </span>
        <span class="text-[12px] text-muted-foreground">{{ labelText }}</span>
    </button>
</template>
