<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

const props = defineProps<{
    checked: boolean;
    label: string;
    /** While a bulk run is in flight the selection is frozen. */
    disabled?: boolean;
    class?: HTMLAttributes['class'];
}>();

const emit = defineEmits<{
    'update:checked': [value: boolean];
}>();

// Cards are links and rows are clickable: swallow the click here so ticking
// never navigates or opens the row. A click on the padding toggles too.
function onWrapperClick(event: MouseEvent): void {
    if (props.disabled) {
        return;
    }

    if (!(event.target instanceof Element) || !event.target.closest('button')) {
        emit('update:checked', !props.checked);
    }
}
</script>

<template>
    <span
        :class="cn('inline-flex', props.class)"
        @click.stop.prevent="onWrapperClick"
    >
        <Checkbox
            :model-value="checked"
            :disabled="disabled"
            :aria-label="label"
            @update:model-value="
                (value) => emit('update:checked', value === true)
            "
        />
    </span>
</template>
