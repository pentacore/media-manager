<script setup lang="ts" generic="T extends string">
import { cn } from '@/lib/utils';

defineProps<{
    options: Array<{ value: T; label: string }>;
    modelValue: T;
    /** When set, each button gets data-<optionAttribute>="<value>". */
    optionAttribute?: string;
}>();

defineEmits<{
    'update:modelValue': [value: T];
}>();
</script>

<template>
    <div
        class="flex items-center gap-0.5 rounded-md border border-border bg-bg-elev p-0.5"
        role="group"
    >
        <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
            :aria-pressed="modelValue === opt.value"
            v-bind="
                optionAttribute
                    ? { [`data-${optionAttribute}`]: opt.value }
                    : {}
            "
            :class="
                cn(
                    'inline-flex h-6 items-center rounded px-2 text-xs font-medium whitespace-nowrap transition-colors',
                    modelValue === opt.value
                        ? 'bg-accent text-accent-foreground'
                        : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                )
            "
            @click="$emit('update:modelValue', opt.value)"
        >
            {{ opt.label }}
        </button>
    </div>
</template>
