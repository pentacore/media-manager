<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * One settings row: the label (a Field) in a 200 px column beside its control
 * from `sm` up; below `sm` the label stacks above a full-width control.
 * Inside a `SettingsGrid` the row stacks again from `xl` up, where it sits in
 * a narrower column; a `wide` row spans the whole grid and keeps the label
 * beside the control (for a list, table, textarea or chart).
 */
const props = defineProps<{
    class?: HTMLAttributes['class'];
    wide?: boolean;
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
                props.wide
                    ? 'col-span-full'
                    : 'xl:in-data-settings-grid:grid-cols-1 xl:in-data-settings-grid:gap-2',
                props.class,
            )
        "
    >
        <slot name="label" />
        <slot />
    </div>
</template>
