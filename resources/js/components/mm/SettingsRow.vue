<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * One settings row: the label (a Field) in a 200 px column beside its control
 * from `sm` up; below `sm` the label stacks above a full-width control.
 */
const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

defineSlots<{
    label: () => unknown;
    default: () => unknown;
}>();
</script>

<template>
    <div
        data-settings-row
        :class="
            cn(
                'grid grid-cols-1 items-start gap-2 sm:grid-cols-[200px_minmax(0,1fr)] sm:gap-6',
                props.class,
            )
        "
    >
        <slot name="label" />
        <slot />
    </div>
</template>
