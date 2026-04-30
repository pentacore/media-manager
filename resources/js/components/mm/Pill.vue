<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

type Variant = 'default' | 'ok' | 'warn' | 'danger' | 'info';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        dot?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        variant: 'default',
        dot: false,
    },
);

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'ok':
            return 'text-success border-success/30 bg-success/10';
        case 'warn':
            return 'text-warning border-warning/30 bg-warning/10';
        case 'danger':
            return 'text-destructive border-destructive/30 bg-destructive/10';
        case 'info':
            return 'text-info border-info/30 bg-info/10';
        default:
            return 'text-muted-foreground border-border bg-bg-elev';
    }
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex h-[22px] items-center gap-1.5 rounded-full border px-2 text-[11.5px] font-medium tabular-nums whitespace-nowrap',
                variantClasses,
                props.class,
            )
        "
    >
        <span
            v-if="dot"
            class="size-1.5 rounded-full bg-current"
            aria-hidden="true"
        />
        <slot />
    </span>
</template>
