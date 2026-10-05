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
    <SegmentedControl
        :options="options"
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
    />
</template>
