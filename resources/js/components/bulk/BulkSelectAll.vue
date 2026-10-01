<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';

const props = withDefaults(
    defineProps<{
        selectedCount: number;
        pageCount: number;
        label?: string;
        hideLabel?: boolean;
        /** While a bulk run is in flight the selection is frozen. */
        disabled?: boolean;
    }>(),
    { label: 'Select all on this page', hideLabel: false, disabled: false },
);

const emit = defineEmits<{
    toggle: [value: boolean];
}>();

const state = computed<boolean | 'indeterminate'>(() => {
    if (props.pageCount === 0 || props.selectedCount === 0) {
        return false;
    }

    return props.selectedCount >= props.pageCount ? true : 'indeterminate';
});
</script>

<template>
    <label
        class="inline-flex cursor-pointer items-center gap-2 text-[12px] text-muted-foreground"
        data-bulk-select-all
        @click.stop
    >
        <Checkbox
            :model-value="state"
            :disabled="disabled || pageCount === 0"
            :aria-label="label"
            @update:model-value="(value) => emit('toggle', value === true)"
        />
        <span :class="hideLabel ? 'sr-only' : ''">{{ label }}</span>
    </label>
</template>
