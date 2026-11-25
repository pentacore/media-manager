<script setup lang="ts">
import { cn } from '@/lib/utils';

/**
 * Lays a section's settings rows out across wide screens: one column below
 * `xl` (today's layout), two from `xl` (1280 px) and three from 1800 px
 * (`maxColumns` 2 stops at two). Rows fill left to right in source order and
 * align to the top of their line. A `SettingsRow` inside stacks its label
 * above the control from `xl` up; a `wide` row spans the whole grid.
 */
const props = withDefaults(
    defineProps<{
        maxColumns?: 2 | 3;
    }>(),
    { maxColumns: 3 },
);

defineSlots<{
    default: () => unknown;
}>();
</script>

<template>
    <div
        data-settings-grid
        :class="
            cn(
                'grid grid-cols-1 items-start gap-x-8 gap-y-5 xl:grid-cols-2',
                props.maxColumns === 3 && 'xl:min-[1800px]:grid-cols-3',
            )
        "
    >
        <slot />
    </div>
</template>
