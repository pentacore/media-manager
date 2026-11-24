<script setup lang="ts">
import SegmentedControl from './SegmentedControl.vue';

defineProps<{
    options: Array<{ value: string; label: string }>;
    modelValue: string;
}>();

defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <!-- A caller's own aria-label or aria-labelledby falls through and wins. -->
    <SegmentedControl
        aria-label="Time window"
        :options="options"
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
    />
</template>
