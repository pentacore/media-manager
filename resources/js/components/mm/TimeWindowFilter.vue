<script setup lang="ts">
import { cn } from '@/lib/utils';

defineProps<{
    options: Array<{ value: string; label: string }>;
    modelValue: string;
}>();

defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <div
        class="flex items-center gap-0.5 rounded-md border border-border bg-bg-elev p-0.5"
    >
        <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
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
